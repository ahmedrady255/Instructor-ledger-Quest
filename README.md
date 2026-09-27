# Instructor Revenue Ledger

Laravel 11 take-home implementation for deterministic subscription revenue recognition, append-only instructor balances, safe payout reservation, idempotent provider execution, refunds, reconciliation, and a read-only Filament summary.

## Start and verify

Requirements: Docker with Compose. All PHP, Node, MySQL, and Redis dependencies run in the supplied containers.

```bash
cp .env.example .env
docker compose build app
docker compose up -d mysql redis app nginx queue scheduler
docker compose exec app php artisan migrate:fresh --seed
docker compose run --rm test ./vendor/bin/pest
```

Open `http://localhost/admin` and sign in as `admin@example.test` / `password`. The seed is deterministic and safe to rerun. Stop with `docker compose down`; add `-v` only when intentionally deleting local database/Redis volumes.

Useful commands:

```bash
docker compose exec app php artisan revenue:recognize
docker compose exec app php artisan instructors:payout --currency=EGP --limit=1000 --dry-run
docker compose exec app php artisan instructors:reconcile-payouts --older-than=5m --limit=1000
docker compose exec app php artisan instructors:ledger-control --date=2026-09-27
docker compose exec app php artisan horizon:status
docker compose exec app php artisan schedule:list
```

## Design

- Money is signed `BIGINT` minor units; currency is an uppercase ISO code. No floating-point arithmetic is used.
- Access terms are half-open UTC ranges: `term_start` is included and `term_end` is excluded.
- Platform, daily, instructor, and refund allocation use floor arithmetic plus deterministic largest remainders.
- Ledger history is append-only. Refunds add linked negative adjustments or future cancellation allocations.
- MySQL transactions, unique keys, restrictive foreign keys, conditional state changes, and the active-reservation generated column are authoritative.
- Redis supplies queues, rate limits, cache, and owner-safe coordination only. Redis loss cannot authorize another payout.
- A persisted payout idempotency key is reused for every provider call. `UNKNOWN` remains reserved until reconciliation resolves it.
- Whole positive earning entries are reserved, as permitted by the PRD. Partial-entry allocation and an outbox are deliberate scope cuts.

The code is organized by business domain (`Revenue`, `Ledger`, `Payouts`) because the invariants and vocabulary cross HTTP, jobs, commands, and persistence. Framework modules remain at their normal Laravel boundaries. This avoids duplicated money/state rules in feature-shaped modules.

Pest 3 is the test framework because it integrates with Laravel/PHPUnit while keeping allocation matrices, datasets, feature tests, Livewire/Filament tests, and queue assertions compact. Run formatting with:

```bash
docker compose run --rm app ./vendor/bin/pint --test
```

## Interfaces and operations

- `POST /api/subscription-payments` and `POST /api/refunds` require `Idempotency-Key` and are rate limited.
- Horizon uses separate `recognition`, `payouts`, and `reconciliation` queues.
- The Filament resource is verified-user-only and read-only; payout destination snapshots are never rendered.
- `MOCK_PROVIDER_OUTCOME=success|permanent_failure|timeout_after_success` controls local provider demonstrations.
- See [docs/runbook.md](docs/runbook.md) for recovery procedures. Never replace an `UNKNOWN` payout manually.

## Acceptance evidence

| Acceptance area | Evidence |
| --- | --- |
| AC01–AC04 money, platform, daily, and instructor conservation | `tests/Unit/Domain`, `tests/Feature/Acceptance/FinancialInvariantsTest.php` |
| AC05–AC06 payment validation and durable idempotency | `tests/Feature/Payments/RecordSubscriptionPaymentTest.php` |
| AC07 recognition retry safety and balances | `tests/Feature/Revenue`, `tests/Feature/Ledger` |
| AC08–AC10 append-only refunds and deficits | `tests/Feature/Refunds`, `tests/Unit/Domain/Revenue/RefundAllocatorTest.php` |
| AC11–AC13 reservation ownership and repeat commands | `tests/Feature/Payouts/CreateInstructorPayoutTest.php`, `tests/Feature/Commands` |
| AC14 provider idempotency and timeout recovery | `tests/Feature/Payouts/ProcessInstructorPayoutTest.php` |
| AC15 reconciliation and `UNKNOWN` protection | `tests/Feature/Payouts/CheckPayoutStatusTest.php` |
| AC16 read-only UI, controls, Redis failure, and demo | `tests/Feature/Filament`, `tests/Feature/Acceptance` |

See [Instructor_Revenue_Ledger_PRD.md](Instructor_Revenue_Ledger_PRD.md) for the supplied requirements and [the implementation plan](docs/superpowers/plans/2026-09-26-instructor-revenue-ledger.md) for task-level decisions.
