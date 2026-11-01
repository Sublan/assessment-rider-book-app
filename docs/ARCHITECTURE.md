# Architecture and decisions

Answers to the eight questions in the brief, plus the driver-matching design.
Everything described here is implemented unless it is explicitly labelled as a
production plan.

```
                                   ┌─────────────────────────────────────────────┐
  Rider / Driver (browser)         │  apps/web — React 18 + Vite + TypeScript    │
  ┌───────────────────────────────┐│                                             │
  │ LoginModal (Rider / Driver)   ││  POST /api/auth/rider/login                 │
  │ BookingForm & Map             ││  POST /api/auth/driver/login                │
  │ DriverDashboard & Live Feed   ││  GET  /api/driver/feed (incoming & active)  │
  │ RideStatus & Simulator        ││  POST /api/rides/estimate                   │
  └───────────────┬───────────────┘│  POST /api/rides  (Idem-Key)                │
                  │                │  GET  /api/rides/{id}  (poll 3s)            │
                  │                └──────────────────────┬──────────────────────┘
                  │  same origin (/api) — no CORS         │ HTTPS/JSON
                  ▼                                       ▼
  ┌──────────────────────────────────────────────────────────────────────────┐
  │ apps/api — Laravel 12                                                    │
  │                                                                          │
  │  routes/api.php                                                          │
  │      │                                                                   │
  │  Middleware: AssignRequestId → ResolveActor → throttle:…                 │
  │      │                                                                   │
  │  Controllers (AuthController, RideController, DirectoryController)        │
  │      │                                                                   │
  │  FormRequest  ──────────────►  Service layer (all business rules)        │
  │                                  RideService    TripPlanner              │
  │                                  DriverService  FareCalculator           │
  │                                  IdempotencyService  DriverMatcher       │
  │      │                                    │                              │
  │  Presenter (wire format)                  │ one DB transaction per        │
  │  ErrorResponse (one envelope)             │ state transition              │
  └───────────────────────────────────────────┼──────────────────────────────┘
                                              ▼
  ┌──────────────────────────────────────────────────────────────────────────┐
  │ MySQL 8+ (WAMP or Docker)                                                │
  │  riders · drivers · rides · driver_assignments · ride_events ·           │
  │  idempotency_keys                                                        │
  │                                                                          │
  │  rides_one_active_per_rider_idx  unique functional/partial index         │
  │  rides_one_active_per_driver_idx unique functional/partial index         │
  └──────────────────────────────────────────────────────────────────────────┘
```

## 1. Why this stack and this shape

**Laravel 12 + MySQL 8 + React/TypeScript.**

Laravel because the problem is a transactional CRUD domain with strict state
transitions: its form requests, service container, migration tooling and testing
helpers remove ceremony, and its transaction API (`DB::transaction`) is enough
that the locking logic reads like the specification. The brief cares about
correctness, not novelty, and this stack lets a reviewer follow one ride from
route to row in four files.

MySQL because the two hardest requirements — *no two drivers on one ride* and
*no duplicate ride per rider* — are invariants, and the strongest place to
express an invariant is the database. MySQL 8.0.13+ supports functional indexes,
so we express both invariants as a unique index over an expression that is `null`
for every row that should not participate, making double booking physically
impossible rather than merely unlikely:

```sql
-- MySQL 8+ Functional Unique Index (what the migration runs):
create unique index rides_one_active_per_driver_idx
    on rides ((case when status = 'accepted' then driver_id else null end));

create unique index rides_one_active_per_rider_idx
    on rides ((case when status in ('requested', 'accepted') then rider_id else null end));
```

MySQL allows any number of `null`s in a unique index, which is exactly the
"partial" behaviour we need. SQLite was rejected outright: it cannot express a
functional unique index, so the guarantee would degrade to application code.

React + Vite because the UI is a two-step flow with a polling status view; a
small SPA with one stylesheet and zero UI dependencies keeps the diff readable
and the tests fast. TypeScript mirrors the API's wire shape so a renamed field
fails the build instead of the demo.

**Separation.** `routes → middleware → FormRequest → Controller → Service →
Repository (Eloquent models) → Presenter`. Controllers contain no rules
(`RideController::accept` is three lines), services contain no HTTP, and
presenters own the wire format so the same array can be returned or stored as an
idempotent response snapshot. Fares live in `FareCalculator` (pure, no DB),
eligibility in `DriverMatcher` (pure), and the lifecycle in
`RideStateMachine` + `RideService` — each unit-testable without a kernel.

**Money.** Integer paisa everywhere. `fare_total_minor` is computed once, at
request time, and stored as a snapshot, so a tariff change never rewrites the
price of a ride that already happened.

## 2. How we prevent two drivers from accepting the same ride

Three layers, in order of how much we trust them.

**(a) Row lock, then re-read.** `RideService::accept()` opens a transaction and
does:

```php
$ride = Ride::query()->whereKey($rideId)->lockForUpdate()->first();  // SELECT … FOR UPDATE
```

That blocks any competing accept on the same ride. When the lock is released the
second transaction *re-reads the committed row*, sees `status = 'accepted'`, and
returns `409 RIDE_ALREADY_ACCEPTED` with the winning `driverId`. It never
overwrites the winner. The loser's transaction also rolls back the driver row it
had locked, so that driver stays `available`.

**(b) Conditional update as a belt-and-braces check.** Even though the lock
already serialises writers, the write itself is a compare-and-set:

```php
$updated = DB::table('rides')
    ->where('id', $rideId)->where('status', 'requested')   // ← still requested?
    ->update([... 'status' => 'accepted', 'driver_id' => $driverId ...]);

if ($updated !== 1) throw ApiException::rideAlreadyAccepted();
```

If a future refactor drops the lock, this still refuses to overwrite state it
did not read.

**(c) The database refuses the result.** `rides_one_active_per_driver_idx` is a
functional unique index: a driver cannot appear on two `accepted` rides. If any
code path — a console command, a backfill, a migration — ever tried, MySQL
rejects it with duplicate-key error `1062` (SQLSTATE `23000`).
`RideService::translateUniqueViolation()` converts that into the same
`409 DRIVER_BUSY` the happy path returns, so the client sees one contract
regardless of which layer caught it.

**What the tests prove** (`tests/Feature/ConcurrentAcceptanceTest.php`):

- two drivers, one ride: the second gets `409`, `ride.driver_id` is the first
  driver, exactly one `driver_assignments` row exists, and the loser is still
  `available`;
- a direct `UPDATE` that bypasses the service is rejected by the index;
- a **second database session** writing to a ride row locked by the first blocks
  and trips the InnoDB lock-wait timeout (error `1205`) — i.e. writers really do
  serialise on the row rather than both succeeding.

## 3. Retries and duplicate requests

Two different problems, two different mechanisms.

**Unsafe retries → `Idempotency-Key`.** `POST /rides` accepts an optional key.
`IdempotencyService::claim()` runs *inside the same transaction as the insert*:

```sql
insert ignore into idempotency_keys (key, scope, request_hash, created_at)
values (?, ?, ?, now())
-- 1 affected row = the key is ours for this attempt, 0 = it already existed
```

- The claim commits or rolls back **with** the ride. A request that fails
  validation leaves no key behind, so a key is never poisoned by an attempt that
  never happened (asserted in `IdempotencyTest`).
- `insert ignore` rather than a plain insert matters: a plain insert raises
  duplicate-key error `1062`, and the caller would have to unwind the whole
  transaction just to read the row that blocked it. With `insert ignore`, a
  concurrent duplicate waits for the winning transaction to finish, then reports
  0 affected rows, at which point we read the committed attempt and replay its
  stored response.
- The stored `response_body` is replayed verbatim, so a retry sees the ride *as
  it was created* (status `requested`), not its current state. The live resource
  is still available at `GET /rides/{id}`.
- `request_hash` is a SHA-256 of the canonicalised body. Same key + different
  body → `422 IDEMPOTENCY_KEY_REUSED` instead of silently returning somebody
  else's ride.
- Scope is namespaced per rider (`rides.create:{riderId}`), so one client cannot
  squat on another's key.
- Rows are pruned by `rides:prune-idempotency-keys` after
  `RIDE_IDEMPOTENCY_TTL_HOURS`.

**Duplicate ride requests without a key → the schema.** A rider can only have one
live ride:

```sql
create unique index rides_one_active_per_rider_idx
    on rides (rider_id) where status in ('requested', 'accepted');
```

`RideService::create()` also checks first (returning `409 ACTIVE_RIDE_EXISTS`
with the existing `rideId`), but the index is what makes the guarantee true
under concurrency.

**On the client.** The SPA generates one key per *booking attempt* and keeps it
until the attempt succeeds (`App.tsx`), so a failed request that actually
succeeded server-side replays instead of creating a second ride. Changing the
pickup, destination or category resets the key, because that is a different
request.

## 4. How the frontend responds when an API request fails

`apps/web/src/api/client.ts` normalises every failure into one `ApiError` with
`code`, `message`, `details`, `requestId` and `status`. A network failure becomes
`NETWORK_ERROR` with status `0` rather than a raw `TypeError`. From there:

- **Field validation (`422`)** → the banner shows the server's message plus the
  first field error; inputs stay filled in, nothing is lost.
- **Conflict (`409`)** → shown as an actionable message with the error code (e.g.
  "Another driver was assigned to this ride first"). The ride is also re-polled,
  so the UI converges on the truth instead of sitting on stale state.
- **Retryable (`5xx`, `429`, `NETWORK_ERROR`)** → a **Try again** button is
  offered. Non-retryable failures do not show one, because a 422 will fail
  identically no matter how many times it is tapped.
- **Estimate failures** are contained to the estimate card; the form stays usable
  and the Request button is disabled until a price exists.
- **Poll failures** show "Live updates paused" with a retry, but keep the last
  known ride on screen.
- **Bootstrap failure** (API unreachable) shows a full-page error with a retry
  that refetches locations and riders.

In-flight estimates are aborted when the inputs change (`AbortController`), so a
slow quote for the previous destination can never overwrite the price of the
current one.

## 5. Scaling to multiple cities and thousands of concurrent users

**Cheap, already-shaped-for-it steps**

- `city` is a column on `rides` and `drivers`; matching filter already includes
  it. Adding a city is configuration, not code. Next step is a `cities` table
  with its own tariff and service-area polygon, and making the tariff lookup
  `city → category → rates` instead of `category → rates`.
- The API is stateless (no sessions, no in-process state), so it scales
  horizontally behind a load balancer. `SESSION_DRIVER=file` is only used for
  web routes; the ride API never touches it.
- Reads (`GET /rides/{id}`) are the highest-volume call because the status view
  polls. They are single-row indexed lookups; put a read replica behind them, or
  replace polling with websockets/SSE (below).

**Where the work actually goes**

- `POST /rides` does one fare computation (in-memory), one indexed candidate
  query and one short transaction. That is ~3 queries; the pool is the limit, so
  raise the connection pool (MySQL `max_connections`, or ProxySQL in front) before
  anything else.
- The candidate query is `where status='available' and is_active and city = ?`
  using `drivers_available_idx`. At city scale that is thousands of rows — fine.
  At 100k drivers it is not, which is where geospatial prefiltering comes in
  (below).
- Partition `rides` by month (or by city + month) once history grows: the hot
  set is the last few hours, and old rides are only read by support tooling.
- Prune `idempotency_keys` and `ride_events` on a schedule (the command exists).

**Nearby-driver discovery in production** (the brief's "you may simplify this"
allowance, and how to un-simplify it):

1. Drivers heartbeat their position every ~4s into **Redis** (`GEOADD drivers:lahore`).
2. On request, `GEOSEARCH … BYRADIUS 3km` returns the nearest N driver ids in
   ~O(log N) — no table scan, no PostGIS needed for the hot path.
3. Filter by eligibility (online, active, category) using a small Redis hash
   per driver, then confirm against MySQL before assigning.
4. If the first radius yields nobody, expand to 5km, 8km, and so on — with a cap
   so nobody gets matched to a driver 30 minutes away.
5. MySQL remains the source of truth for *state* (who is on a trip); Redis is
   a cache of *position*, and is allowed to be lossy.

That keeps the assignment rule in one place — the row lock and the unique index —
no matter where the candidate list came from.

## 6. When to introduce Redis, a broker, or separate services

Not before there is a measured reason. The order I would reach for them:

| Trigger | Add | Why not before |
| --- | --- | --- |
| p95 on `GET /rides/{id}` degrades because of polling volume | WebSocket/SSE fan-out with Redis pub/sub | Polling is one indexed read and survives restarts; it is the cheapest correct thing until it is measurably expensive |
| Driver positions updating every few seconds | Redis GEO + a heartbeat endpoint | MySQL would take an `UPDATE` per driver per 4s; pointless while the fleet is small |
| Dispatch fan-out (offer → timeout → next driver) or notifications | Message broker (SQS/RabbitMQ) with a worker pool | The current flow is synchronous and deterministic: a driver accepts, or the ride waits. A queue is correct once offers expire and must be retried on a schedule |
| Rate limiting across many API instances | Redis-backed limiter | Laravel's limiter is per-process with `CACHE_STORE=file`; today that is a coarse safety net, not a global quota |
| Team/ownership boundaries, or one endpoint with a wildly different scaling profile | Split out a service (e.g. pricing, dispatch) | A monolith with clear service classes is easier to keep correct; splitting early buys distributed transactions and loses the single-transaction guarantee that makes §2 simple |

The rule of thumb: introduce a component when you can name the metric it fixes.

## 7. Deploy, monitor, roll back

**Deploy.** `docker-compose.yml` is the local shape; production is the same two
images on any container host (ECS/Fargate, Cloud Run, Kubernetes):

1. CI runs `composer install --no-dev`, `php artisan test`, `npm ci`,
   `npm run typecheck:web`, `npm run test:web`, `npm run build:web`.
2. Build and tag images with the commit SHA (never `latest`).
3. Migrations run from the **entrypoint** (`php artisan migrate --force`) — they
   are additive and forward-only, so old and new pods can run side by side.
   Destructive changes ship as *expand → migrate → contract* across two deploys.
4. Deploy the new API, wait for health (`GET /up`, plus `/api/health`), then the
   web image. Both are behind a load balancer with rolling updates, so a bad pod
   never takes traffic.

**Monitor.** Structured JSON logs (request id on every line via
`AssignRequestId`, request id also returned in every error envelope), plus:

- RED metrics per route (rate, errors, duration) — the p95 of `POST /rides` is
  the number that tells you the matcher or the lock is the bottleneck;
- `SELECT count(*) FROM performance_schema.data_lock_waits` (or
  `SHOW ENGINE INNODB STATUS` → `TRANSACTIONS` → lock waits) and a lock-wait
  alert: a spike here means contention on `rides`;
- business counters: rides requested / accepted / cancelled / completed, and
  **accept-failure ratio** (409s per 100 accepts) — a jump means riders and
  drivers are racing badly;
- a synthetic check that creates and cancels a ride every minute.

**Roll back.** Because images are immutable and tagged by SHA, rollback is
"deploy the previous tag". Migrations are the only irreversible part, which is
exactly why they are additive: a rollback to the previous app version must be
able to run against the new schema. If a release is bad because of data, the
feature flag or the offending write path gets disabled first, then the image is
rolled back — never a database restore unless the data itself is corrupt.

## 8. Three most important improvements before production

1. **A real dispatch loop with cascading offers and timeouts.** Right now a ride is broadcast to eligible drivers and assigned to the first to accept. Production needs: an automated dispatch engine offering the ride exclusively to the top candidate for 15 seconds, expiring and cascading to the next nearest driver if unacknowledged.
2. **Turn-by-turn routing & geofencing engine.** Replace the Haversine + circuity formula with OSRM, Valhalla, or Google Maps Directions API to account for Lahore one-ways, ring-road tolls, canal crossings, and historical rush-hour traffic.
3. **PCI-compliant digital payment processing.** Integrate local payment gateways (Easypaisa, JazzCash, PayFast, Stripe) using two-phase payment authorization upon booking and final capture upon trip completion.

Close behind: PCI-scoped payment capture, real geocoding/routing (the fare maths
uses a 1.28 road detour factor), and PII handling for phone numbers.
