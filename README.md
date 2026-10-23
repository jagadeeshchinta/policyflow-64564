# PolicyFlow

**Fault-Tolerant Insurance Issuance & Reconciliation Engine**

A Laravel-based API for processing travel insurance policy requests with emphasis on reliability, idempotency, and safe state management.

## Architecture Overview

```
┌─────────────────────────────────────────────────────────┐
│                    API Layer (REST)                       │
│  POST /api/policy-requests     → Create/return existing  │
│  GET  /api/policy-requests/{id}→ Retrieve with audit     │
│  GET  /api/policy-requests     → List/filter/paginate    │
│  POST /api/policy-requests/{id}/submit → Issue to insurer│
│  POST /api/mock-insurer/callback → Receive provider event│
├─────────────────────────────────────────────────────────┤
│                  Service Layer                           │
│  PremiumCalculator  → bcmath-based pricing               │
│  IssuanceService    → State machine + insurer calls      │
├─────────────────────────────────────────────────────────┤
│                  Data Layer (MySQL)                       │
│  policy_requests     → Core entity + unique constraints  │
│  issuance_attempts   → Audit trail of insurer calls      │
│  provider_events     → Idempotent callback processing    │
│  policy_state_history→ Full state transition log         │
└─────────────────────────────────────────────────────────┘
```

### State Machine

```
RECEIVED → VALIDATED → SUBMITTED → PENDING_CONFIRMATION → ISSUED
                  ↓           ↓              ↓
                FAILED ← ─ ─ ─ ─ ─ ─ ─ ─ ─ ┘
                  ↓                          ↓
              SUBMITTED ←──────── NEEDS_RECONCILIATION
```

Invalid regressions (e.g., `ISSUED → SUBMITTED`) are rejected by the state machine.

## Prerequisites

- Docker & Docker Compose
- Git

## Local Setup

```bash
# 1. Clone the repository
git clone <repo-url>
cd policyflow

# 2. Copy environment file
cp .env.example .env

# 3. Start Docker containers
docker-compose up -d --build

# 4. Wait for MySQL to be healthy (about 30 seconds)
docker-compose ps  # Check all services are "Up"

# 5. Generate application key
docker-compose exec app php artisan key:generate

# 6. Run migrations
docker-compose exec app php artisan migrate

# 7. Application is now running at:
# http://localhost:8000
```

## Running Tests

```bash
# Run all tests
docker-compose exec app php artisan test

# Run with verbose output
docker-compose exec app php artisan test --verbose

# Run specific test file
docker-compose exec app php artisan test --filter=PremiumCalculatorTest
docker-compose exec app php artisan test --filter=PolicyRequestApiTest
```

## API Documentation

### 1. Create Policy Request (Idempotent)

```bash
POST /api/policy-requests
Content-Type: application/json

{
  "client_request_id": "AGT-2026-0001",
  "agent_id": "AGENT-17",
  "customer_name": "Sample Customer",
  "date_of_birth": "1998-04-10",
  "destination": "INTERNATIONAL",
  "trip_start": "2026-11-10",
  "trip_end": "2026-11-14",
  "plan_code": "PLUS",
  "sum_insured": 300000
}
```

- **201** — Newly created request
- **200** — Existing request returned (same `agent_id + client_request_id`)
- **422** — Validation errors

### 2. Get Policy Request

```bash
GET /api/policy-requests/{id}
```

Returns full details including state history, issuance attempts, and provider events.

### 3. List Policy Requests

```bash
GET /api/policy-requests?status=VALIDATED&agent_id=AGENT-17&per_page=10
```

Supports filtering by `status`, `agent_id`, and pagination (`per_page`, `page`).

### 4. Submit for Issuance

```bash
POST /api/policy-requests/{id}/submit
Content-Type: application/json

{
  "scenario": "SUCCESS"
}
```

Available scenarios: `SUCCESS`, `TEMPORARY_FAILURE`, `TIMEOUT_AFTER_ISSUE`, `DELAYED_CALLBACK`, `DUPLICATE_CALLBACK`.

### 5. Mock Insurer Callback

```bash
POST /api/mock-insurer/callback
Content-Type: application/json

{
  "event_id": "EVT-UNIQUE-001",
  "policy_request_id": 1,
  "event_type": "POLICY_ISSUED",
  "policy_number": "POL-12345",
  "provider_reference": "REF-12345"
}
```

## Premium Calculation Formula

```
premium = base_daily_rate × travel_days × age_multiplier × destination_multiplier × coverage_multiplier
```

| Component | Values |
|---|---|
| Base daily rate | BASIC=₹12, PLUS=₹20, PREMIUM=₹30 |
| Age multiplier | 0-17: 1.10, 18-60: 1.00, 61-70: 1.50, 71+: 2.00 |
| Destination | DOMESTIC: 1.00, INTERNATIONAL: 1.80 |
| Coverage | ₹100K: 1.00, ₹300K: 1.25, ₹500K: 1.50 |
| Travel days | trip_end - trip_start + 1 (minimum 1) |

Example: PLUS, 5 days, age 28, INTERNATIONAL, ₹300K = 20 × 5 × 1.00 × 1.80 × 1.25 = **₹225.00**

## Implemented Reliability Scenarios

### 1. Duplicate Intake (agent_id + client_request_id)

**Problem:** The same policy request may be submitted multiple times, including near-simultaneous requests.

**Solution:**
- **Database-level unique constraint** on `(agent_id, client_request_id)` — the primary defense
- **Application-level check** before INSERT — returns existing record with HTTP 200
- **UniqueConstraintViolationException handling** — catches race conditions where two requests pass the check simultaneously
- The second request always returns the original record, never creates a duplicate

### 2. Duplicate Callback (same event_id)

**Problem:** The insurer may deliver the same callback event multiple times.

**Solution:**
- **Unique constraint on `event_id`** in the `provider_events` table
- **Application-level check** before processing — returns "duplicate" status
- **Database-level catch** via `UniqueConstraintViolationException` — handles race conditions
- Events are recorded once; state transitions happen exactly once
- An already-`ISSUED` policy cannot be modified by subsequent callbacks (terminal state guard)

### 3. Timeout After Success & Reconciliation Engine

**Problem:** The insurer creates the policy, but the client connection times out before receiving the HTTP response. Blind re-issuance would create a duplicate policy and double-charge premium.

**Solution:**
- When connection times out, `IssuanceService` catches `ConnectionException` and transitions request to `NEEDS_RECONCILIATION`.
- The attempt is recorded with result `TIMEOUT`.
- **Artisan Reconciliation Command:**
  ```bash
  docker compose exec app php artisan policyflow:reconcile
  # Or reconcile a specific ID:
  docker compose exec app php artisan policyflow:reconcile --id=3
  ```
- The engine checks `GET /api/mock-insurer/status/{id}`. If the policy was issued, it retrieves the `policy_number` and transitions the request to `ISSUED`. If the insurer has no record, it transitions to `FAILED`.

### 4. Retry After Temporary Failure

**Problem:** Insurer returns transient 503 error; retry must not create duplicate records or lose state.

**Solution:**
- Transient failure transitions request to `FAILED` and records attempt #1.
- State machine explicitly allows `FAILED → SUBMITTED` transition.
- Re-submitting executes attempt #2, updates same policy request record, increments `attempt_count`, and safely transitions to `ISSUED`.

## Duplicate Prevention Strategy

1. **Database constraints** are the primary defense (unique indexes on `agent_id + client_request_id` and `event_id`)
2. **Pessimistic locking** (`SELECT ... FOR UPDATE`) prevents concurrent state modifications
3. **Application-level checks** provide fast-path responses without hitting constraint violations
4. **Transaction isolation** ensures atomicity of state transitions + audit logging
5. **Idempotent design** — retrying any operation produces the same result

## Database Design

- **policy_requests** — Core entity with all request data, calculated premium (immutable), and current status
- **issuance_attempts** — Every call to the insurer is recorded with full request/response payloads
- **provider_events** — Incoming callbacks with unique `event_id` for deduplication
- **policy_state_history** — Complete audit trail with from/to states, reason, trigger source, and timestamp

## Automated Test Suite

PolicyFlow includes **34 automated tests (131 assertions)** with 100% pass rate:
- **Unit Tests:** `PremiumCalculatorTest` (all plans, age brackets, domestic/international multipliers, edge cases, single-day trip, exception handling).
- **Feature Tests:** `PolicyRequestApiTest` (intake validation, idempotency, issuance state machine, duplicate callbacks, timeout & reconciliation command, temporary failure retry).

```bash
docker compose exec app php artisan test
```

## Tech Stack

- **Language:** PHP 8.4 (CLI & FPM)
- **Framework:** Laravel 12+
- **Database:** MySQL 8.0 (InnoDB with row-level pessimistic locking)
- **Containerization:** Docker & Docker Compose with Nginx reverse proxy
- **Testing:** PHPUnit (34 tests, 131 assertions)
- **Math:** `bcmath` for decimal-safe financial calculations
