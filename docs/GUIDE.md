# Developer guide — read the code, run the project

This is the "start here" document. It explains **what the project is**, **how to
run it from zero**, **how to read the code in the order it executes**, and **why
each piece is the way it is**. The other two documents are references:
[`ARCHITECTURE.md`](ARCHITECTURE.md) answers the eight design questions, and
[`openapi.yaml`](openapi.yaml) is the API contract.

If you only have five minutes: run the Quick start, then read
[§5.2](#52-accepting-a-ride-the-one-that-must-not-go-wrong). That function is
the heart of the exercise.

---

## 1. What you are looking at

A rider opens a web page, chooses a pickup point and a destination in Lahore,
picks **Economy** or **Comfort**, sees a fare and a pickup ETA, requests a ride,
and watches the status change until it is completed or cancelled. A driver can
accept a ride, and exactly one driver can ever win.

Two applications, one repository:

```
Repository root
├── apps/api          Laravel 12 REST API + PostgreSQL schema   (PHP)
├── apps/web          Rider app, React + Vite + TypeScript       (Node)
├── scripts/pg.mjs    Starts/stop a local PostgreSQL 17 cluster
├── docs/             This guide, architecture answers, OpenAPI
├── docker-compose.yml  Postgres + PHP-FPM + nginx, one command
└── package.json      npm scripts that drive both apps
```

There is no AI, no external API, no map key, no login provider, no payment
processor. Everything runs on your machine.

---

## 2. Stack: what is used and why

| Piece | Version | Why it is here | Why not the alternative |
| --- | --- | --- | --- |
| **Laravel** | 12 | The domain is transactional CRUD with strict state transitions. Form requests, migrations, `DB::transaction`, the test helper and the container remove boilerplate so the locking logic reads like the spec. | Express/NestJS would work, but you would hand-roll transactions, validation, migrations and testing glue. |
| **Database** | MySQL 8+ (WAMP) / PostgreSQL 16+ | Two invariants — *one live ride per rider*, *one accepted ride per driver* — are best expressed as functional unique indexes (MySQL) or partial unique indexes (PG). | SQLite has no functional/partial unique index semantics and no JSON validation. |
| **Eloquent + query builder** | — | Models for reads/relations; the query builder for the two places where exact SQL matters (`lockForUpdate`, the guarded `update … where status = …`). | Raw SQL everywhere would be unreadable; ORM everywhere hides the locks. |
| **PHPUnit** | 11 | Laravel's `artisan test` runner; feature tests hit real routes, unit tests hit pure classes. | Pest is nicer syntax but adds a dependency for no behavioural gain. |
| **React + TypeScript** | 18 / 5.7 | Two-step flow with polling. Types mirror the API's wire shape, so a renamed field fails the build. | A meta-framework (Next/Inertia) adds SSR and routing this app does not need. |
| **Vite** | 5 | Dev server with an `/api` proxy (no CORS in dev), production build in ~750 ms. | Webpack is slower with no benefit here. |
| **Vitest + Testing Library** | 2 / 16 | Tests the UI the way a rider uses it (roles, labels, text), not implementation details. | Jest needs more config for ESM + TS. |
| **embedded-postgres** | 17.5 | Ships a Postgres binary so `npm run db:up` gives you a real database without installing a server. Dev-only. | Requiring a local Postgres install makes "clone and run" fragile. |
| **nginx + PHP-FPM** | — | Docker path: one origin serving the SPA and proxying `/api`, so no CORS in production either. | A Node server would duplicate what nginx already does. |

**Deliberate non-choices:** no Redis, no message broker, no ORM-heavy domain
layer, no state-management library, no UI kit, no authentication provider. Each
is listed with its trigger in [`ARCHITECTURE.md §6`](ARCHITECTURE.md#6-when-to-introduce-redis-a-broker-or-separate-services).

---

## 3. Repository map

### Backend — `apps/api`

| Path | What it does | Read it when |
| --- | --- | --- |
| `routes/api.php` | Every endpoint + throttle. This is the table of contents of the API. | First |
| `bootstrap/app.php` | Wires routes, middleware and the **error envelope**. | You want to know how errors are shaped |
| `app/Http/Middleware/AssignRequestId.php` | Puts a correlation id on every request, response and log line | Debugging |
| `app/Http/Middleware/ResolveActor.php` | Turns `X-Rider-Id` / `X-Driver-Id` into the current actor (the auth stub) | You are replacing auth |
| `app/Http/Requests/*.php` | Input validation (`CreateRideRequest`, `EstimateRequest`, `CancelRideRequest`, `DriverStatusRequest`) | Adding a field |
| `app/Http/Controllers/RideController.php` | Thin: read actor → call service → present. No rules. | Tracing a request |
| `app/Http/Controllers/DirectoryController.php` | `/locations`, `/riders`, `/drivers`, driver availability, `/health` | Demo support endpoints |
| `app/Services/RideService.php` | **The lifecycle.** Create, accept, cancel, complete; every method is one transaction | The whole point |
| `app/Services/TripPlanner.php` | Price + ETA + candidate list for a trip (shared by estimate and create) | Fare questions |
| `app/Services/FareCalculator.php` | Pure fare maths, integer paisa | Changing prices |
| `app/Services/DriverMatcher.php` | Eligibility filter + deterministic ranking (distance → rating → id) | Changing matching |
| `app/Services/IdempotencyService.php` | Claims keys and replays stored responses | Retry questions |
| `app/Services/DriverService.php` | Driver availability rules (a driver cannot mark themselves `on_trip`) | Availability |
| `app/Support/RideStateMachine.php` | The transition table, in one place | Invalid-transition questions |
| `app/Support/Geo.php` | Haversine, road-detour factor, ETA minutes. Pure. | Distance maths |
| `app/Support/LahoreLocations.php` | 10 pickup points + the service-area bounding box | Adding places |
| `app/Support/ActorContext.php` | Per-request holder for the current rider/driver id | Auth changes |
| `app/Enums/ApiErrorCode.php` | Every error code **and its HTTP status** | Client error handling |
| `app/Enums/RideStatus.php` | `allowedTransitions()` — requested → accepted → completed, plus cancellations | Lifecycle |
| `app/Exceptions/ApiException.php` | The one exception type services throw, with named constructors | Adding an error |
| `app/Http/Presenters/RidePresenter.php` | Wire shape of a ride (same array is stored for idempotent replays) | Changing the response |
| `app/Http/ErrorResponse.php` | Builds `{ error: { code, message, details, requestId } }` | Error contract |
| `database/migrations/2026_*.php` | Schema, CHECK constraints, **partial unique indexes** | Understanding the invariants |
| `database/seeders/DatabaseSeeder.php` | 2 riders + 8 drivers with fixed UUIDs (idempotent) | Demo data |
| `config/ride.php` | Tariffs, speeds, candidate limits, idempotency TTL — all env driven | Configuration |
| `routes/console.php` | `rides:prune-idempotency-keys` + its daily schedule | Housekeeping |
| `tests/Feature/*`, `tests/Unit/*` | See [§8](#8-the-tests-and-what-each-one-proves) | Verifying behaviour |

A few files are **untouched Laravel scaffolding**, not part of the ride domain:
`app/Models/User.php`, `database/factories/UserFactory.php`, the
`0001_01_01_*` migrations (users / cache / jobs — the framework's defaults),
`resources/`, `apps/api/vite.config.js` and `apps/api/package.json`. This project
has no auth and the SPA is a separate app, so nothing in the ride flow touches
them; the cache and jobs tables are simply the framework's out-of-the-box
database tables. They are left in place so the skeleton stays upgradable — ignore
them when reading the domain code.

### Frontend — `apps/web/src`

| Path | What it does |
| --- | --- |
| `main.tsx` | Mounts `<App />` in StrictMode |
| `App.tsx` | Owns all state: bootstrap (locations/riders/drivers), the draft booking, the active ride, and the actions (request, cancel, accept, complete) |
| `api/client.ts` | `fetch` wrapper, `ApiError` with `code`/`status`/`isRetryable`, one function per endpoint |
| `api/types.ts` | Wire types + category labels/blurbs |
| `hooks/useEstimate.ts` | Live quote for the current trip; aborts in-flight requests so a stale price can never win |
| `hooks/useRidePolling.ts` | Polls `GET /rides/{id}` every 3s until the ride is terminal, then stops |
| `components/BookingForm.tsx` | Pickup/destination, swap, category cards, estimate panel, submit |
| `components/RideStatusView.tsx` | Status pill, fare/trip stats, driver card, timeline, cancel, "book another" |
| `components/DriverSimulator.tsx` | Demo-only stand-in for the driver app (Accept / Complete) |
| `components/MapPlaceholder.tsx` | Schematic SVG map — no tiles, no key |
| `components/States.tsx` | `Spinner`, `ErrorBanner` (with retry policy), `EmptyState`, `EstimateSkeleton` |
| `lib/format.ts` | Money (`PKR 1,234`), distance, duration, time, idempotency key |
| `styles.css` | Single stylesheet, CSS variables, responsive at 880px, reduced-motion support |
| `__tests__/App.test.tsx` | Loading, success, error+retry, empty, request flow, cancellation |

---

## 4. Run it from zero

### 4.1 Prerequisites

- **PHP 8.2+** with `pdo_pgsql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `curl`
  (`php -m | grep pdo_pgsql` must print something)
- **Composer 2**
- **Node 20+** and npm
- Optional: **Docker** (alternative path in §4.7)

You do **not** need to install PostgreSQL: the project ships a binary via the
`embedded-postgres` dev dependency and a helper script.

### 4.2 Install dependencies (once)

```bash
cd apps/api
composer install
cd ../..

npm install          # installs the web app + the local Postgres helper
```

Verifies: `apps/api/vendor/autoload.php` exists, `node_modules/vite` exists.

### 4.3 Start the database (once per boot session)

```bash
npm run db:up
```

This initialises `.pgdata/` on first run, starts PostgreSQL on
`127.0.0.1:5433`, and creates two databases: `ridebook` (dev) and
`ridebook_test` (tests). Expected output:

```
initialising a new cluster in …/.pgdata
created database ridebook
created database ridebook_test
postgres ready on 127.0.0.1:5433 (db ridebook, user postgres)
```

Check it any time with `node scripts/pg.mjs status`. Stop it with
`npm run db:down` (data is kept in `.pgdata/`, which is git-ignored).

> Why port 5433? So it never collides with a Postgres you already run on 5432.

### 4.4 Configure the API

```bash
cd apps/api
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

`.env.example` already points at `pgsql` / `127.0.0.1:5433` / `ridebook`.
`migrate --seed` prints one line per migration and then seeds 2 riders and 8
drivers. Expected tail:

```
2026_01_01_000005_create_idempotency_keys_table ......... DONE
INFO  Seeding database.
```

### 4.5 Run both apps (two terminals)

```bash
# terminal 1 — API on http://127.0.0.1:8000
npm run dev:api

# terminal 2 — SPA on http://127.0.0.1:5173
npm run dev:web
```

Open **http://127.0.0.1:5173**. Vite proxies `/api/*` to the API, so the browser
only ever talks to one origin.

### 4.6 Confirm it works

| Check | Command | Expect |
| --- | --- | --- |
| API up | `curl -s http://127.0.0.1:8000/api/health` | `{"data":{"status":"ok",…}}` |
| Data seeded | `curl -s http://127.0.0.1:8000/api/drivers \| head -c 120` | JSON array starting with Ali Raza |
| UI up | open `http://127.0.0.1:5173` | form pre-filled with Gulberg III → DHA Phase 5, fare `PKR 1,234`, ETA `4 min` |
| Full flow | click **Request ride**, then **Accept** in the driver panel, then **Complete trip** | status pill goes `requested → accepted → completed`, timeline grows to three rows |

### 4.7 Alternative: Docker

```bash
docker compose up --build     # Postgres + PHP-FPM + nginx
open http://localhost:8080
```

Migrations and the demo seed run automatically on the API container's first
boot. The compose file contains a **throwaway `APP_KEY`** so the stack boots
without setup — replace it for anything real. This path was not executed on the
machine this project was built on (no Docker daemon there); the path in §4.2–4.6
is the one the test suite runs against.

### 4.8 Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| `could not find driver` from artisan | `pdo_pgsql` not enabled | enable `extension=pdo_pgsql` in the `php.ini` CLI PHP loads (`php --ini`) |
| `SQLSTATE[08006] connection refused` | Postgres not running | `npm run db:up`, then `node scripts/pg.mjs status` |
| `database "ridebook" does not exist` | cluster started but DB missing | `node scripts/pg.mjs ensure` (it creates both databases) |
| API tests fail on `migrate:fresh` | test DB missing | same as above — `ridebook_test` is created by the helper |
| SPA shows "RideBook is not reachable" | API not running, or wrong port | start `npm run dev:api`; the proxy target defaults to `:8000` |
| Port already in use | another server | API: `php artisan serve --port=8001`; web: `vite --port 5174`; then set `VITE_API_TARGET` |

### 4.9 Reset everything

```bash
cd apps/api && php artisan migrate:fresh --seed   # schema + demo data
npm run db:down && rm -rf .pgdata && npm run db:up  # nuke the cluster entirely
```

---

## 5. Reading the code: one request end to end

### 5.1 Creating a ride — `POST /api/rides`

Follow the call stack; each step has one job.

**1. `routes/api.php`** — the route exists and is throttled:

```php
Route::post('/rides', [RideController::class, 'store'])->middleware('throttle:30,1');
```

**2. Middleware** (`bootstrap/app.php` → `$middleware->api(append: [...])`):

- `AssignRequestId` — stores a uuid on the request and in the log context.
- `ResolveActor` — reads `X-Rider-Id`; a malformed value is rejected here so no
  controller ever sees a half-trusted id.

**3. `CreateRideRequest`** — shape validation: `category` ∈ {economy, comfort},
`pickup.label` 2–160 chars, coordinates in range. A failure throws
`ValidationException`, which `bootstrap/app.php` renders as
`422 {error:{code:"VALIDATION_ERROR", details:{field:[…]}}}`.

**4. `RideController::store`** — a handful of lines: require the rider, read and
length-check the idempotency key, delegate to the service, return `{data: …}`
with `Idempotent-Replayed: false`.

**5. `RideService::create`** — the interesting part:

```php
// (a) price it OUTSIDE the transaction: pure work never holds a lock
$plan = $this->planner->plan($category, …pickup, …destination, $city);

// (b) all writes inside one transaction
$body = DB::transaction(function () use (…) {
    if ($idempotencyKey !== null) {
        $this->idempotency->claim($idempotencyKey, $scope, $requestHash);   // may replay
    }

    if (! Rider::query()->whereKey($riderId)->exists()) throw ApiException::riderNotFound();

    $active = Ride::query()->where('rider_id', $riderId)
        ->whereIn('status', RideStatus::activeValues())->lockForUpdate()->first();
    if ($active !== null) throw ApiException::activeRideExists($active->id);

    $ride = Ride::create([... fare snapshot ...]);
    $this->recordEvent($ride, 'ride_requested', 'rider', $riderId, [...]);
    …
});
```

Three deliberate choices:

- **Fare computed before the transaction.** A rejected trip (outside Lahore, too
  short) never takes a lock or opens a transaction.
- **The fare is stored, not recomputed.** `fare_total_minor` and its breakdown are
  written at request time, so a tariff change never rewrites history.
- **The duplicate check is advisory; the index is authoritative.** Two
  simultaneous requests can both pass `$active === null`; the partial unique
  index then rejects one, and `translateUniqueViolation()` turns SQLSTATE `23505`
  into the same `409 ACTIVE_RIDE_EXISTS` the check would have thrown.

**6. `RidePresenter::present`** — one function decides the JSON shape, and the
same array is stored as the idempotent snapshot. That is why a replayed create
returns the ride *as it was created*, not its current state.

### 5.2 Accepting a ride: the one that must not go wrong

`RideService::accept` is the answer to "how do you prevent two drivers from
accepting the same ride". Read it top to bottom:

```php
return DB::transaction(function () use ($rideId, $driverId): Ride {
    // 1. Lock the ride. A competing accept blocks here.
    $ride = Ride::query()->whereKey($rideId)->lockForUpdate()->first()
        ?? throw ApiException::rideNotFound($rideId);

    // 2. Lock the driver too, so the same driver cannot take two rides at once.
    $driver = Driver::query()->whereKey($driverId)->lockForUpdate()->first()
        ?? throw ApiException::driverNotFound($driverId);

    // 3. The loser of a race wakes up here and sees 'accepted'.
    if ($ride->status === RideStatus::Accepted) {
        throw ApiException::rideAlreadyAccepted($ride->driver_id);
    }

    RideStateMachine::assertCanTransition($ride->status, RideStatus::Accepted);
    $this->assertDriverEligible($driver, $ride);      // active, online, city, category, has location

    // 4. Conditional update: only proceed if the row is STILL requested.
    $updated = DB::table('rides')
        ->where('id', $rideId)
        ->where('status', RideStatus::Requested->value)
        ->update(['status' => 'accepted', 'driver_id' => $driverId, 'accepted_at' => now(), …]);

    if ($updated !== 1) throw ApiException::rideAlreadyAccepted();

    $this->setDriverStatus($driverId, DriverStatus::OnTrip);
    DriverAssignment::create(['ride_id' => $rideId, 'driver_id' => $driverId, 'assigned_at' => now()]);
    $this->recordEvent($ride, 'ride_accepted', 'driver', $driverId, [...]);
    return $ride->load(['driver', 'events']);
});
```

Terminology, because it matters when you explain it:

- `SELECT … FOR UPDATE` is a **pessimistic write lock**. The second transaction
  waits; it does not read stale data, and it does not fail — it is serialised.
- The `UPDATE … WHERE status = 'requested'` is a **compare-and-set**. Even if a
  future refactor removed the lock, this refuses to overwrite what it did not
  read.
- The unique index in the schema is the **last line of defence**: no code path,
  including raw SQL, can produce two accepted rides for one driver.

The rejection the loser sees:

```json
{ "error": { "code": "RIDE_ALREADY_ACCEPTED",
             "message": "Another driver was assigned to this ride first",
             "details": { "driverId": "2222…01" },
             "requestId": "…" } }
```

### 5.3 The invariants, in SQL

`database/migrations/2026_01_01_000003_create_rides_table.php`:

```sql
-- a rider can only have one live ride
create unique index rides_one_active_per_rider_idx
    on rides (rider_id) where status in ('requested', 'accepted');

-- a driver can only be bound to one accepted ride
create unique index rides_one_active_per_driver_idx
    on rides (driver_id) where status = 'accepted';
```

There are also **shape constraints**, which are the cheapest possible defence
against inconsistent rows:

```sql
check (status <> 'accepted'  or (driver_id is not null and accepted_at is not null))
check (status <> 'completed' or (driver_id is not null and completed_at is not null))
check (status <> 'cancelled' or (cancelled_at is not null and cancelled_by is not null))
check (fare_base_minor >= 0 and … and surge_multiplier >= 1.00)
```

See [§6](#6-the-database-in-words) for the full picture.

### 5.4 One error envelope, one code table

Every failure — validation, domain, unexpected — is rendered by
`bootstrap/app.php` through `ErrorResponse::make()`:

```json
{ "error": {
    "code": "INVALID_STATE_TRANSITION",
    "message": "Cannot cancel a ride that is completed",
    "details": { "from": "completed", "action": "cancel" },
    "requestId": "0f3c…" } }
```

`ApiErrorCode` maps each code to its status, so a service only picks a *code*,
never a number:

| Code | Status | Raised when |
| --- | --- | --- |
| `VALIDATION_ERROR` | 422 | bad body, malformed `X-Rider-Id` |
| `MISSING_ACTOR` | 401 | no identity header |
| `FORBIDDEN` | 403 | not the rider, not the assigned driver |
| `RIDE_NOT_FOUND` / `RIDER_NOT_FOUND` / `DRIVER_NOT_FOUND` | 404 | unknown id |
| `ACTIVE_RIDE_EXISTS` | 409 | rider already has a live ride (`details.rideId`) |
| `RIDE_ALREADY_ACCEPTED` | 409 | another driver won |
| `INVALID_STATE_TRANSITION` | 409 | e.g. completing a `requested` ride |
| `DRIVER_NOT_ELIGIBLE` / `DRIVER_BUSY` | 409 | offline, inactive, wrong vehicle, on a trip |
| `IDEMPOTENCY_KEY_REUSED` / `IDEMPOTENCY_IN_PROGRESS` | 422 / 409 | same key, different body / still running |
| `OUTSIDE_SERVICE_AREA`, `TRIP_TOO_SHORT`, `TRIP_TOO_LONG` | 422 | geography |
| `RATE_LIMITED` | 429 | throttle |
| `INTERNAL_ERROR` | 500 | anything unexpected (logged, never leaked) |

Domain failures are **not** written to the error log (`dontReport([ApiException::class])`),
so `storage/logs/laravel.log` only contains genuine incidents.

### 5.5 Idempotency, in one SQL statement

`IdempotencyService::claim()`:

```sql
insert into idempotency_keys (key, scope, request_hash, created_at)
values (?, ?, ?, now())
on conflict (key, scope) do nothing
returning key
```

- **No rows returned** → the key already exists. Read it: same hash but no
  response yet means "in progress"; same hash with a response means *replay*;
  different hash means `IDEMPOTENCY_KEY_REUSED`.
- **`on conflict do nothing`, not a plain insert**, because a failed statement
  aborts the whole PostgreSQL transaction. With `on conflict`, a concurrent
  duplicate simply waits and then returns zero rows.
- **The claim is inside the ride's transaction**, so a failed request rolls the
  claim back with it — a key is never poisoned by an attempt that never happened.
- **Scope is per rider** (`rides.create:{riderId}`), so keys cannot collide
  between clients.

`ReplayResponse` is the control-flow signal used to unwind the transaction and
hand back the stored body; `RideService::create` catches it and returns
`Idempotent-Replayed: true`.

### 5.6 Fare and ETA

`TripPlanner::plan()` is the seam shared by `POST /rides/estimate` and
`POST /rides`, so the price the rider sees and the price they are charged come
from the same code:

```php
$distance = Geo::roadDistanceMeters(...);              // haversine × 1.28 road factor
$duration = Geo::durationSecondsFor($distance, 22);    // km/h from config
$fare     = $this->fares->quote($category, $distance, $duration);
$candidates = $this->candidates($category, $lat, $lng, $city);
return ['distanceMeters' => …, 'pickupEtaMinutes' => $candidates[0]['eta_minutes'] ?? null, …];
```

`FareCalculator::quote()` in integer paisa:

```
total = max( round((base + per_km × km + per_min × minutes) × surge), minimum )
```

Rounding happens **once**, at the end, so quoting the same trip twice is always
byte-identical (asserted in `FareCalculatorTest`).

`DriverMatcher` filters then ranks: active → `available` → same city → has a
location → vehicle class can serve the category (a comfort car can serve economy,
never the reverse). Ranking is `distance, then rating, then id`, which is
deterministic — the same two equidistant drivers always produce the same order.

---

## 6. The database in words

```
riders                     drivers                        rides
──────                     ───────                        ─────
id (uuid pk)               id (uuid pk)                   id (uuid pk)
full_name                  full_name                      rider_id ─────► riders
phone (unique)             phone (unique)                 driver_id ────► drivers (nullable)
created_at/updated_at      city                           status: requested|accepted|cancelled|completed
                           vehicle_category: eco|comfort  category: economy|comfort
                           vehicle_model / plate          city
                           rating 1.0-5.0                 pickup_*   + destination_* (label, lat, lng)
                           status: available|on_trip|offline   distance_meters, duration_seconds
                           is_active                      pickup_eta_minutes
                           current_lat / current_lng      fare_* (base, distance, time, minimum, total, surge)
                                                          requested_at/accepted_at/completed_at/cancelled_at
                                                          cancelled_by, cancellation_reason
                                                          ── unique(rider_id) where status in (requested,accepted)
                                                          ── unique(driver_id) where status = accepted

driver_assignments                      ride_events                    idempotency_keys
──────────────────                      ───────────                    ────────────────
id, ride_id (unique) ──► rides          id, ride_id ──► rides          (key, scope) primary key
driver_id ───────────► drivers          event_type                     request_hash
assigned_at, ended_at, end_reason       actor_type, actor_id           response_code, response_body
                                        metadata (jsonb), created_at   resource_id, created_at
```

Why the split:

- `rides.driver_id` is the **current state**; `driver_assignments` is the
  **history** (when this driver held this ride and how it ended). Cancelling and
  completing both close the assignment rather than deleting it.
- `ride_events` is an **append-only log**, written in the same transaction as the
  state change it describes. It is what the UI renders as the timeline, and it is
  the fastest way to answer "what happened to this ride".
- `idempotency_keys` is a **claim table**, not a cache.

---

## 7. The frontend in five minutes

`App.tsx` holds all state; there is no store, no reducer, no router. The page has
two modes, driven by `activeRide`:

```
boot ──► BookingForm ──request──► RideStatusView ──finished──► "Book another ride" ──► BookingForm
             │                          ▲
             │ useEstimate              │ useRidePolling (3s, until terminal)
             ▼                          │
      estimate panel                    └── cancel / accept / complete actions
```

- **`useEstimate`** returns `{status: 'idle' | 'loading' | 'success' | 'error'}`.
  Changing pickup, destination or category aborts the in-flight request — a slow
  quote for the old destination can never overwrite the current price.
- **`useRidePolling`** polls every 3 s while the ride is `requested` or
  `accepted`, stops at a terminal state, and exposes `setRide` so an action
  (accept/cancel) updates the view immediately instead of waiting for the next
  tick.
- **`ApiError`** carries `code`, `message`, `details`, `requestId`, `status`, and
  an `isRetryable` getter. `ErrorBanner` only shows **Try again** when retrying
  can actually help (5xx, 429, network); a 422 will fail identically.
- **The idempotency key is per booking attempt.** It is created on the first
  submit, kept across failures (a lost response must replay, not duplicate), and
  reset when the rider changes the trip or succeeds.
- **States are explicit, not implicit:** skeleton while quoting, empty state when
  no destination is chosen or no drivers are online, error banner per section
  (estimate, submit, poll, bootstrap), success = the status view.

The map is a hand-drawn SVG with two pins and a dashed route — the brief allows a
placeholder, and it keeps the demo key-free.

---

## 8. The tests, and what each one proves

Run everything: `npm test` (API first, then web). API tests use a **real
PostgreSQL** database (`ridebook_test`), because the design depends on partial
unique indexes, `jsonb` and regex checks that SQLite cannot reproduce.

### API — 80 tests

| File | Proves |
| --- | --- |
| `RideCreationTest` | a ride is created `requested` with a stored fare snapshot; timeline starts with `ride_requested`; unknown category, missing coordinates, missing identity, malformed header, unknown rider, outside Lahore, same pickup/destination are all rejected; a second live ride is `409`; cancelling frees the rider to book again |
| `DriverAssignmentTest` | accept assigns the driver, marks them `on_trip`, writes the assignment; offline / inactive / wrong vehicle / already-on-trip drivers are rejected; comfort cars may serve economy; ownership on availability changes |
| `ConcurrentAcceptanceTest` | two drivers, one ride: exactly one wins, one `driver_assignments` row exists; a raw `UPDATE` bypassing the service is rejected by the index; a second DB session writing a locked ride row blocks (`55P03`) |
| `RideLifecycleTest` | full happy path; rider cancel; cancel frees the driver; assigned driver cancel; a stranger cannot cancel; completed cannot be cancelled; cancelled cannot be accepted; complete requires an accepted ride and the assigned driver; cancelling twice fails |
| `IdempotencyTest` | same key → same ride, `Idempotent-Replayed: true`; 10 retries → 1 ride; same key + different body → 422; short key → 422; two riders may use the same key; a *failed* request leaves no key behind; the replay returns the creation-time snapshot while `GET` shows current state; no key still hits the duplicate rule |
| `EstimateTest` | quote before creating anything; comfort > economy; empty city → no ETA; invalid category; outside area; locations list |
| `FareCalculatorTest` | deterministic; minimum fare; base+distance+time maths; surge applied once; breakdown never exceeds total |
| `DriverMatcherTest` | nearest first; online/active/city/category filters; comfort serves both; tie-break by rating then id; candidate cap; ETA includes dispatch overhead |
| `RideStateMachineTest` | every allowed transition passes, every other pair throws, terminal states are terminal |

### Web — 8 tests

| Test | Proves |
| --- | --- |
| loading | skeleton is shown and Request is disabled while quoting |
| success | fare `PKR 1,234`, ETA `4 min`, drivers nearby, button enabled |
| error + retry | 503 shows the banner, disables submit, and a retry recovers |
| empty | `availableDrivers: 0` renders the "no drivers in this area" state |
| request | creates a ride with `Idempotency-Key` + `X-Rider-Id`, then shows the status view |
| submit failure | 409 keeps the form usable and shows the code |
| cancel | cancelling flips the status to `cancelled` and offers "Book another ride" |
| bootstrap | unreachable API shows a retry, and retrying recovers |

---

## 9. Everyday changes

| Task | Do this |
| --- | --- |
| **Change prices** | edit `FARE_*` variables in `apps/api/.env` (paisa). No code change — `config/ride.php` reads them. |
| **Change speeds / candidate limit** | `RIDE_AVG_SPEED_KMH`, `RIDE_APPROACH_SPEED_KMH`, `RIDE_MAX_CANDIDATES` |
| **Add a pickup point** | append to `LahoreLocations::all()` (id, label, area, lat, lng). The picker and validation pick it up. |
| **Extend the service area** | `LahoreLocations::BOUNDS` |
| **Add a ride category** | `RideCategory` enum, `config/ride.php` pricing block, `CATEGORY_LABELS`/`CATEGORY_BLURBS` in `api/types.ts`, and the `category in (…)` CHECK in the rides migration |
| **Add an endpoint** | route in `routes/api.php` → FormRequest → controller → service → presenter; add the path to `docs/openapi.yaml` |
| **Change the wire shape** | `RidePresenter` only (that is why it is a plain transformer) |
| **Replace header identity with real auth** | `ResolveActor` → populate `ActorContext` from a verified session/JWT. Nothing else changes. |
| **Reset demo data** | `php artisan migrate:fresh --seed` |
| **Prune old idempotency keys manually** | `php artisan rides:prune-idempotency-keys` |

### Useful manual checks

```bash
# price a trip
curl -s -X POST http://127.0.0.1:8000/api/rides/estimate \
  -H 'content-type: application/json' \
  -d '{"category":"economy","pickup":{"lat":31.5204,"lng":74.3440},"destination":{"lat":31.4697,"lng":74.4123}}'

# create it (note the key), then repeat the same command and watch Idempotent-Replayed
curl -s -X POST http://127.0.0.1:8000/api/rides \
  -H 'content-type: application/json' \
  -H 'x-rider-id: 11111111-1111-4111-8111-111111111111' \
  -H 'Idempotency-Key: manual-attempt-0001' \
  -d '{"category":"economy",
       "pickup":{"label":"Gulberg III","lat":31.5204,"lng":74.3440},
       "destination":{"label":"DHA Phase 5","lat":31.4697,"lng":74.4123}}'

# accept as driver 1, then try driver 2 — expect 409
curl -s -X POST http://127.0.0.1:8000/api/rides/<ride-id>/accept -H 'x-driver-id: 22222222-2222-4222-8222-222222222201'
```

Watching for problems:

- `apps/api/storage/logs/laravel.log` — only unexpected errors land here, with the
  same `requestId` the client received in the error envelope.
- `apps/api/.api.log` when started via `npm run dev:api` — the access log.
- Postgres: `node scripts/pg.mjs status`, logs in `.pgdata/server.log`.

---

## 10. Glossary

| Term | Meaning here |
| --- | --- |
| **Actor** | The rider or driver making the request, resolved from headers in `ResolveActor` |
| **Minor units / paisa** | Integer money. `123395` = PKR 1,233.95. Never floats. |
| **Fare snapshot** | The `fare_*` columns written at request time; the price is fixed, not recomputed |
| **Candidate** | A driver who passes the eligibility filter, ranked by distance |
| **Assignment** | A row in `driver_assignments` recording that a driver held a ride |
| **Idempotency key** | Client-supplied token making a retry replay the original response |
| **Partial unique index** | A unique index with a `WHERE` clause; the mechanism that makes double-booking impossible |
| **CAS (compare-and-set)** | `UPDATE … WHERE status = 'requested'`; succeeds only if nobody changed the row |
| **Terminal state** | `completed` or `cancelled` — no transitions out |

## 11. What is deliberately not built

No login/authorisation provider, no payments, no real maps or geocoding, no push
notifications, no websockets (the rider app polls), no SMS, no Redis, no queue.
Each has a trigger for when it *should* appear in
[`ARCHITECTURE.md §6`](ARCHITECTURE.md#6-when-to-introduce-redis-a-broker-or-separate-services),
and the three things to fix before production are in
[`ARCHITECTURE.md §8`](ARCHITECTURE.md#8-three-improvements-before-production).
