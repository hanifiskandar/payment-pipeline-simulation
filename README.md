# Payment Pipeline Simulation

A Laravel 13 CLI application simulating a payment processing pipeline. Fully in-memory — no database, no frontend, no Eloquent.

## Requirements

- PHP 8.3+ with `bcmath` extension
- Composer
- Docker + Docker Compose (optional)

## Setup (without Docker)

```bash
composer install
cp .env.example .env
php artisan key:generate
```

## Running

### Interactive mode (stdin)

```bash
php artisan payment:run
```

### File mode

```bash
php artisan payment:run --file=commands.txt
```

### Docker — interactive

```bash
docker compose run --rm app payment:run
```

### Docker — file mode

```bash
docker compose run --rm app payment:run --file=commands.txt
```

### Docker — run tests

```bash
docker compose run --rm app test --compact
```

## Commands

| Command | Syntax | Description |
|---|---|---|
| `CREATE` | `CREATE <id> <amount> <currency> <merchant_id>` | Create a payment in INITIATED state |
| `AUTHORIZE` | `AUTHORIZE <id>` | Authorize a payment |
| `CAPTURE` | `CAPTURE <id>` | Capture an authorized payment |
| `VOID` | `VOID <id> [reason...]` | Void a payment with optional reason |
| `REFUND` | `REFUND <id> <amount>` | Partial or full refund |
| `SETTLE` | `SETTLE <id>` | Mark a captured payment as settled |
| `SETTLEMENT` | `SETTLEMENT <batch_id>` | Record a settlement batch |
| `STATUS` | `STATUS <id>` | Show payment status and metadata |
| `LIST` | `LIST` | List all payments |
| `AUDIT` | `AUDIT <id>` | Acknowledge audit receipt (no side effects) |
| `EXIT` | `EXIT` | Exit the session |

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

## Comment Syntax

A standalone `#` token at position 3 or later (1-indexed) is treated as a comment delimiter — everything from it onward is ignored.

```
CREATE P1001 10.00 MYR M01 # comment here   → valid, comment stripped
AUTHORIZE P1001 # retrying                  → valid, # at position 3
# CREATE P1001 10.00 MYR M01               → NOT a comment — # at position 1 becomes unknown command
VOID P1001 REASON#CODE                      → embedded # not a delimiter — reason is "REASON#CODE"
```

## Running Tests

```bash
php artisan test --compact
```

## Design Decisions

### In-memory storage
`PaymentStorageService` is a Laravel singleton backed by plain PHP arrays. This is deliberate — the simulation has no persistence requirements and no DB dependency, keeping startup instantaneous.

### BCMath for amounts
All arithmetic uses `bcadd`, `bccomp`, `bcsub` with scale=2. This avoids float precision errors (e.g. `0.1 + 0.2 !== 0.3` in PHP floats). Amounts are stored as strings throughout.

### PRE_SETTLEMENT_REVIEW
Auto-triggered inside `AuthorizeHandler` after a successful AUTHORIZED transition when `amount > config('payment.review_threshold')` (default 500, configurable via `REVIEW_THRESHOLD` env var). The state service remains a pure FSM; business rules live in the handler layer.

### SETTLE vs SETTLEMENT
- `SETTLE <payment_id>` — transitions a single payment from CAPTURED → SETTLED. Idempotent.
- `SETTLEMENT <batch_id>` — records that a settlement batch was processed. Does NOT change any payment state. Prints a summary of currently SETTLED payments.

### Partial refunds
`refunded_amount` accumulates via `bcadd` on each `REFUND` call. The state only transitions to `REFUNDED` once `refunded_amount == amount`. Multiple partial refunds are allowed up to the original amount.

### AUDIT command
Zero side effects by design. It does not validate that the payment exists, does not mutate any state, and does not write to the audit log. It simply acknowledges receipt.

### Comment rule
A `#` is only a comment when it appears as a standalone whitespace-separated token at position 3 or later. An embedded `#` within a token (e.g. `REASON#CODE`) is treated as a regular character.

## What Would Be Different in Production

| Area | Production approach |
|---|---|
| Storage | PostgreSQL/MySQL with Eloquent models and proper migrations |
| State transitions | Queue-based jobs (e.g. `AuthorizePaymentJob`) with retry logic |
| Idempotency | Idempotency keys stored in Redis with TTL |
| Audit trail | Dedicated `payment_audit_log` table with indexed `payment_id` |
| Refunds | Ledger-style `payment_refunds` table per refund event |
| Webhooks | Event listeners firing `PaymentSettled`, `PaymentRefunded` etc. |
| Observability | Structured logging (JSON), metrics, distributed tracing |
| Auth | API keys / OAuth scopes per merchant |
| Concurrency | Optimistic locking (`updated_at` compare-and-swap) or pessimistic DB row locks |
