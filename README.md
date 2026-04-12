# Payment Pipeline Simulation

A command-line application that simulates a simplified payment processing pipeline, built with Laravel 13 and PHP 8.4. Fully in-memory — no database, no frontend, no Eloquent.

---

## Tech Stack

| | |
|---|---|
| Language | PHP 8.4 |
| Framework | Laravel 13 |
| Runtime | php:8.4-cli (Docker) |
| Testing | PHPUnit 12 |
| Arithmetic | BCMath (fixed precision, no float errors) |
| Storage | In-memory PHP array via singleton service |

---

## Requirements

**With Docker (recommended):**
- Docker
- Docker Compose

**Without Docker:**
- PHP 8.4+ with `bcmath` and `zip` extensions
- Composer

---

## Setup & Running

### With Docker (recommended)

Build the image:
```bash
docker-compose build
```

Interactive mode:
```bash
docker-compose run --rm app payment:run
```

File mode:
```bash
docker-compose run --rm app payment:run --file=demo/01_happy_path.txt
```

Run tests:
```bash
docker-compose run --rm app php artisan test
```

### Without Docker

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Interactive mode:
```bash
php artisan payment:run
```

File mode:
```bash
php artisan payment:run --file=demo/01_happy_path.txt
```

Run tests:
```bash
php artisan test
```

---

## Demo Files

The `demo/` folder contains ready-made command files for each scenario:

| File | Scenario |
|------|----------|
| `01_happy_path.txt` | Full lifecycle: CREATE → AUTHORIZE → CAPTURE → SETTLE |
| `02_pre_settlement_review.txt` | High amount triggering PRE_SETTLEMENT_REVIEW |
| `03_partial_refund.txt` | Multiple partial refunds accumulating to full refund |
| `04_void.txt` | Void from INITIATED and AUTHORIZED states |
| `05_idempotency.txt` | Duplicate CREATE and SETTLE idempotency |
| `06_invalid_transitions.txt` | All invalid transition rejections |
| `07_settlement_batch.txt` | Batch settlement reporting |
| `08_full_demo.txt` | Full demonstration of all features |

---

## Commands

| Command | Syntax | Description |
|---|---|---|
| `CREATE` | `CREATE <id> <amount> <currency> <merchant_id>` | Create a payment in INITIATED state |
| `AUTHORIZE` | `AUTHORIZE <id>` | Authorize a payment |
| `CAPTURE` | `CAPTURE <id>` | Capture an authorized payment |
| `VOID` | `VOID <id> [reason...]` | Void a payment with optional reason |
| `REFUND` | `REFUND <id> [amount]` | Partial or full refund (omit amount = full refund) |
| `SETTLE` | `SETTLE <id>` | Mark a captured payment as settled |
| `SETTLEMENT` | `SETTLEMENT <batch_id>` | Record a settlement batch (reporting only) |
| `STATUS` | `STATUS <id>` | Show payment status and metadata |
| `LIST` | `LIST` | List all payments |
| `AUDIT` | `AUDIT <id>` | Acknowledge audit receipt (no side effects) |
| `EXIT` | `EXIT` | Exit the session |

---

## State Machine

```
INITIATED ──AUTHORIZE──▶ AUTHORIZED ──CAPTURE──▶ CAPTURED ──SETTLE──▶ SETTLED
    │                        │                      │                     │
   VOID                     VOID                  REFUND               REFUND
    │                        │                      │                     │
    ▼                        ▼                      ▼                     ▼
  VOIDED                  VOIDED                REFUNDED             REFUNDED

AUTHORIZED (amount > threshold) ──auto──▶ PRE_SETTLEMENT_REVIEW ──CAPTURE──▶ CAPTURED

Any state ──CREATE conflict──▶ FAILED
```

---

## Configuration

All configurable via environment variables — no source code changes required.

| Variable | Default | Description |
|----------|---------|-------------|
| `REVIEW_THRESHOLD` | `500` | Amount above which PRE_SETTLEMENT_REVIEW is triggered |
| `SUPPORTED_CURRENCIES` | `MYR,USD,SGD,EUR,GBP` | Comma-separated list of accepted currencies |
| `LOG_VERBOSITY` | `normal` | Logging verbosity level |

Override via `.env` file or directly:
```bash
REVIEW_THRESHOLD=1000 docker-compose run --rm app payment:run
```

---

## Comment Syntax

A standalone `#` token at position 3 or later (1-indexed) is treated as a comment delimiter — everything from it onward is ignored.

```
CREATE P1001 10.00 MYR M01 # comment     → valid, comment stripped
AUTHORIZE P1001 # retrying               → valid, # at position 3
# CREATE P1001 10.00 MYR M01            → NOT a comment — treated as malformed command
VOID P1001 REASON#CODE                   → embedded # not a delimiter — reason is "REASON#CODE"
```

---

## Architecture

```
app/
  Console/Commands/PaymentRun.php        ← I/O only, no business logic
  Services/
    PaymentPipelineService.php           ← command orchestration
    PaymentStateService.php              ← state machine logic
    PaymentStorageService.php            ← in-memory storage (singleton)
  Domain/
    Payment.php                          ← plain PHP class
    PaymentState.php                     ← PHP enum
    BatchRecord.php                      ← batch record class
  Parsers/
    CommandParser.php                    ← tokenizes input, applies comment rules
  Handlers/                              ← one handler per command
config/
  payment.php                            ← all configurable values
demo/                                    ← example command files
tests/
  Unit/
    CommandParserTest.php
    PaymentStateTest.php
  Feature/
    PaymentFlowTest.php
```

Four concerns are strictly separated:
- **Parsing** — `CommandParser` tokenizes raw input and applies comment rules. No business logic.
- **Domain** — `Payment`, `PaymentState`, `BatchRecord` define the domain model. No I/O concerns.
- **State management** — `PaymentStateService` owns all transition logic. Throws `InvalidTransitionException` on invalid transitions.
- **I/O** — `PaymentRun` reads input and prints output. Delegates all logic to services.

---

## Design Decisions

### PRE_SETTLEMENT_REVIEW
Auto-triggered inside `AuthorizeHandler` after a successful AUTHORIZED transition when `amount > config('payment.review_threshold')` (default 500, configurable via `REVIEW_THRESHOLD`). The state service remains a pure FSM — business rules live in the handler layer, keeping concerns separated.

### SETTLE vs SETTLEMENT
- `SETTLE <payment_id>` — transitions a single payment from CAPTURED → SETTLED. Idempotent (SETTLED → SETTLED is accepted silently).
- `SETTLEMENT <batch_id>` — a reporting-level operation that records a batch ID and prints a summary of all currently SETTLED payments. Does **not** change any payment state.

### Partial Refunds
`refunded_amount` accumulates via BCMath `bcadd` on each `REFUND` call. State only transitions to `REFUNDED` once `refunded_amount == amount`. Multiple partial refunds allowed up to the original amount. Omitting the amount defaults to a full refund of the remaining balance.

### AUDIT
Zero side effects by design. Does not validate payment existence, does not mutate state, does not write to the audit log. Simply acknowledges receipt with `[AUDIT] RECEIVED for <id>`.

### In-Memory Storage
`PaymentStorageService` is a Laravel singleton backed by plain PHP arrays. Deliberate choice — the simulation has no persistence requirements, keeping the solution focused on state machine correctness.

### BCMath for Amounts
All arithmetic uses `bcadd`, `bccomp`, `bcsub` with scale=2. Avoids float precision errors. Amounts are stored and compared as strings throughout.

---

## Tests

```bash
# With Docker
docker-compose run --rm app php artisan test

# Without Docker
php artisan test
```

Expected: **65 tests, 82 assertions, all passing**

Coverage includes happy path flows, invalid transitions, idempotency, parser behavior, AUDIT zero side effects, and SETTLEMENT batch reporting.

---

## What Would Be Different in Production

| Area | Production Approach |
|---|---|
| Storage | PostgreSQL with Eloquent models and migrations |
| State transitions | Queue-based jobs with retry logic |
| Idempotency | Idempotency keys stored in Redis with TTL |
| Audit trail | Dedicated `payment_audit_log` table, append-only |
| Refunds | Ledger-style `payment_refunds` table per refund event |
| Webhooks | Event listeners firing `PaymentSettled`, `PaymentRefunded` etc. |
| Observability | Structured JSON logging, metrics, distributed tracing |
| Auth | API keys / OAuth scopes per merchant |
| Concurrency | Optimistic locking or pessimistic DB row locks |
| PRE_SETTLEMENT_REVIEW | Fraud scoring service or human review queue |