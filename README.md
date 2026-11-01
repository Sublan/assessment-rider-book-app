# RideBook Lahore

A production-grade, simplified ride-booking platform for Lahore, Pakistan:
**Laravel 12 REST API + MySQL 8** backend with a **React (Vite + TypeScript)** rider & driver application and real-time **Laravel Reverb WebSockets**.

---

## Assessment Requirements Compliance Checklist

| Required Deliverable | Status | Location / Implementation |
| :--- | :---: | :--- |
| **README with setup and execution instructions** | ✅ Done | This document (`README.md`) |
| **Database schema or migrations** | ✅ Done | `apps/api/database/migrations/` (5 core tables + Sanctum + cache + jobs) |
| **Automated tests and instructions to run them** | ✅ Done | 80 backend tests (`npm run test:api`) + 8 frontend tests (`npm run test:web`) |
| **API documentation or example requests** | ✅ Done | OpenAPI 3.1 specification at [`docs/openapi.yaml`](docs/openapi.yaml) + cURL examples below |
| **Architecture diagram and design notes** | ✅ Done | [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) answering all 8 technical questions |
| **Known limitations and proposed improvements** | ✅ Done | Section in `docs/ARCHITECTURE.md` (§8) and detailed below |
| **AI tool disclosure** | ✅ Done | Section below |

---

## AI Tool Disclosure

AI coding tools were used on this submission, as required by §6 of the brief:

| Tool | Role |
| :--- | :--- |
| **Claude Code** | Implementation: writing and editing the API, the SPA, the migrations, the tests and the documentation. |
| **Claude audit skills** | Verification and review: auditing the finished code against the brief, re-reading every edited file, and running the checks below. |

**How the output was verified.** Nothing was accepted on the tool's word alone. Every file
was read back after editing, and the README's claims are limited to commands that can be
re-run from a clean clone:

- `npm run test:api` — 80 PHPUnit tests / 257 assertions against a real MySQL database;
- `npm run test:web` — 8 Vitest tests over the rider and bootstrap flows;
- `npm run typecheck:web` — `tsc --noEmit`;
- manual end-to-end runs of the rider flow (estimate → request → accept → complete/cancel)
  against the running API and a browser, including the failure paths.

Claims that could not be verified this way are labelled as production plans or known
limitations rather than as done.

---

## Architecture Overview

```
┌─────────────────────────────────┐               ┌─────────────────────────────────────┐
│  React SPA (Vite + TypeScript)  │               │  Laravel 12 API                     │
│  apps/web                       │   HTTP/JSON   │  apps/api                           │
│  • Rider Portal (Book, Track)   │ ────────────► │  • Sanctum Multi-Guard Auth         │
│  • Driver Dashboard (Feed, GPS) │               │  • Transactional Ride Lifecycle     │
│  • Laravel Echo (WebSocket)     │ ◄───────────┐ │  • Idempotency & Rate Limiting      │
└─────────────────────────────────┘  WS Broadcast │  • Pure Math Fare & Matcher Engine  │
                                                  │  └──────────────────┬───────────────┘
                                                  │                     │
                                      ┌───────────┴──────────┐          │ One DB tx per
                                      │ Laravel Reverb (WS)  │          │ state transition
                                      │ Port 8080            │          │
                                      └──────────────────────┘          ▼
                                                  ┌─────────────────────────────────────┐
                                                  │  MySQL 8+ (WAMP 3306 / Docker 3307) │
                                                  │  • Functional Partial Unique Indexes│
                                                  │  • Atomic SELECT ... FOR UPDATE     │
                                                  └─────────────────────────────────────┘
```

---

## Quick Start & Execution Instructions

### Prerequisites
- **PHP 8.2+** with `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`
- **Node.js 20+** and `npm`
- **MySQL 8+** (WAMP Server running on `127.0.0.1:3306`, user `root`, blank password)

### 1. Database Setup (MySQL / WAMP)
Create database `ridebook` and test database `ridebook_test` in MySQL (via phpMyAdmin or MySQL CLI):
```sql
CREATE DATABASE IF NOT EXISTS ridebook;
CREATE DATABASE IF NOT EXISTS ridebook_test;
```

Run migrations and database seeder:
```bash
php apps/api/artisan migrate:fresh --seed
```

### 2. Run the Application
Open three terminal windows (or tabs):

```bash
# Terminal 1: Laravel Backend REST API
npm run dev:api
# Runs on http://127.0.0.1:8000

# Terminal 2: Laravel Reverb WebSocket Server (Real-time events)
npm run dev:reverb
# Runs on ws://127.0.0.1:8080

# Terminal 3: React Frontend Application
npm run dev:web
# Runs on http://localhost:5173
```
Visit **`http://localhost:5173`** in your browser.

### Alternative: Docker Compose (Zero-Install)

No local PHP, Node, or MySQL required — just Docker:

```bash
docker compose up --build
```

This spins up **4 containers**:

| Service   | Image               | Purpose                              | Port        |
| :-------- | :------------------ | :----------------------------------- | :---------- |
| `db`      | `mysql:8.0`         | MySQL database                       | `3307:3306` |
| `api`     | PHP 8.2 FPM (Alpine)| Laravel API (migrations + seed)      | internal    |
| `reverb`  | Same as API          | Laravel Reverb WebSocket server      | internal    |
| `web`     | nginx 1.27 (Alpine) | SPA + `/api` proxy + `/app` WS proxy | `8080:80`   |

Once healthy, open **`http://localhost:8080`** — the app is fully functional with demo data seeded.

> To tear down and wipe data: `docker compose down -v`

---

## Authentication & User Roles (Sanctum)

Unlike naive implementations using insecure custom headers, this application uses **Laravel Sanctum Bearer tokens** with multi-guard separation:

- **Rider Account**: End-users who request, track, and cancel rides. Guard: `auth:rider`.
- **Driver Account**: Cab drivers with vehicle category (`Economy`/`Comfort`), registration plate, live GPS coordinates, and online/offline shift toggling. Guard: `auth:driver`.
- **Multi-Session Support**: A rider or driver logged in across multiple browsers or devices maintains active sessions simultaneously. Active ongoing rides automatically hydrate on login.

---

## Automated Tests

The test suite covers every critical business rule, concurrency invariant, fare calculation, and state transition.

Run the entire test suite (both backend and frontend) with one command:
```bash
npm test
```

### Breakdown of Test Suites:

| Suite | Command | Details |
| :--- | :--- | :--- |
| **All Tests** | `npm test` | Runs PHPUnit backend tests and Vitest frontend tests |
| **Backend API** | `npm run test:api` | **80 PHPUnit tests (257 assertions)** covering: <br>• Ride creation & input validation<br>• Concurrency: Two drivers racing to accept the same ride (atomic 409 lock)<br>• Idempotency key replay and reuse protection<br>• State machine transitions (invalid jump rejections)<br>• Driver matching & pricing formulas<br>• Multi-guard Sanctum token authentication |
| **Frontend Web** | `npm run test:web` | **8 Vitest tests** covering loading states, booking flows, cancellation, and API error banners |
| **Typecheck** | `npm run typecheck:web` | TypeScript compiler (`tsc --noEmit`) with strict typing |

---

## API Documentation & Example Requests

Full interactive OpenAPI 3.1 specification is available in [`docs/openapi.yaml`](docs/openapi.yaml).

### Base URL: `http://127.0.0.1:8000/api`

### Key Endpoints:

#### 1. Rider Login (Issue Sanctum Token)
```bash
curl -X POST http://127.0.0.1:8000/api/auth/rider/login \
  -H "Content-Type: application/json" \
  -d '{"phone": "+923001234501"}'
```
*Response returns Sanctum `token`, user profile, and active ongoing ride if any.*

#### 2. Get Fare Estimate & ETA
```bash
curl -X POST http://127.0.0.1:8000/api/rides/estimate \
  -H "Content-Type: application/json" \
  -d '{
    "category": "economy",
    "pickup": {"lat": 31.5204, "lng": 74.3440},
    "destination": {"lat": 31.4697, "lng": 74.4123}
  }'
```

#### 3. Create Ride Request (with Idempotency Key)
```bash
curl -X POST http://127.0.0.1:8000/api/rides \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <RIDER_TOKEN>" \
  -H "Idempotency-Key: a1b2c3d4-e5f6-7890" \
  -d '{
    "category": "economy",
    "pickup": {"label": "Gulberg III", "lat": 31.5204, "lng": 74.3440},
    "destination": {"label": "DHA Phase 5", "lat": 31.4697, "lng": 74.4123}
  }'
```

#### 4. Driver Accept Ride
```bash
curl -X POST http://127.0.0.1:8000/api/rides/<RIDE_UUID>/accept \
  -H "Authorization: Bearer <DRIVER_TOKEN>"
```

#### 5. Driver Complete Ride
```bash
curl -X POST http://127.0.0.1:8000/api/rides/<RIDE_UUID>/complete \
  -H "Authorization: Bearer <DRIVER_TOKEN>"
```

---

## Database Schema & Concurrency Design

### Core Tables
1. **`riders`**: UUID primary key, name, phone, timestamps.
2. **`drivers`**: UUID primary key, name, phone, vehicle tier (`economy`/`comfort`), license plate, model, rating, status (`available`/`on_trip`/`offline`), GPS lat/lng.
3. **`rides`**: UUID, rider_id, driver_id, category, pickup & dropoff coordinates, fare breakdown (integer minor units / paisa), trip distance & duration, timestamps.
4. **`driver_assignments`**: Audit record of which driver was assigned to which ride and duration.
5. **`ride_events`**: Append-only event store for lifecycle tracking (`ride_requested`, `ride_accepted`, `ride_cancelled`, `ride_completed`).
6. **`idempotency_keys`**: Atomic request hash tracking for safe client retries.
7. **`personal_access_tokens`**: Laravel Sanctum polymorphic token storage.

### How Concurrency is Guaranteed:
- **Pessimistic Row Locking (`SELECT ... FOR UPDATE`)**: When a driver attempts to accept a ride, the row is locked inside a transaction.
- **Database Unique Functional Indexes**:
  - `rides_one_active_per_rider_idx`: Ensures a rider can never have more than one active trip.
  - `rides_one_active_per_driver_idx`: Ensures an accepted ride can never be assigned to a second driver. If two drivers click Accept simultaneously, the second transaction is rejected with an HTTP 409 conflict.

---

## Known Limitations & Proposed Improvements

1. **Routing Engine**:
   - *Current*: Straight-line Haversine distance with a 1.35x city winding circuity factor and speed model tuned for Lahore traffic.
   - *Production*: Integrate OSRM / Google Directions API for turn-by-turn routing, road toll detection, and historical traffic patterns.
2. **Dispatch & Expiry Queue**:
   - *Current*: Direct broadcast to drivers; first eligible driver to accept gets the ride.
   - *Production*: Redis-backed dispatch queue (e.g. BullMQ or Laravel Horizon) offering the ride to the closest driver for 15 seconds, cascading to the next nearest driver if unanswered.
3. **Payment Gateways**:
   - *Current*: Cash-on-delivery tracking in PKR paisa (zero floating-point math).
   - *Production*: Integrate local Pakistani payment gateways (Easypaisa, JazzCash, PayFast) or Stripe with two-phase auth-and-capture.
4. **Push Notifications**:
   - *Current*: Real-time updates delivered via WebSockets (Laravel Reverb) with fallback polling.
   - *Production*: FCM (Firebase Cloud Messaging) / APNS push notifications for mobile devices when backgrounded.
