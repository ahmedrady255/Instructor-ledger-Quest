# Instructor Revenue Ledger Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Laravel 11 financial core described by the PRD, including deterministic revenue recognition, an append-only instructor ledger, idempotent payouts and refunds, reconciliation, and a read-only Filament view.

**Architecture:** Keep arithmetic and state rules as database-free domain classes, coordinate persistence in small application actions, and use MySQL constraints plus transactions as the durable correctness boundary. Redis coordinates queues, locks, rate limits, and cached balance reads only; provider-side state keyed by the persisted payout idempotency key prevents duplicate external transfers.

**Tech Stack:** PHP 8.3, Laravel 11, MySQL 8.0, Redis 7, Horizon 5, Filament 3, Livewire 3, Alpine.js, Pest 3, Docker Compose.

**Spec:** `Instructor_Revenue_Ledger_PRD.md`

## Global Constraints

- Use signed `BIGINT` minor units and ISO currency codes; never use floating-point money.
- Treat every access term as the half-open UTC range `[term_start, term_end)`; `term_start` must precede `term_end`.
- Snapshot platform basis points, term boundaries, instructor IDs, weights, currency, and payout destination data at the financial event boundary.
- Use floor arithmetic and stable largest-remainder tie-breaking by instructor ID; assign daily remainder to earliest dates.
- Financial history is append-only. Refunds and corrections add linked compensating rows; no financial foreign key cascades on delete.
- MySQL constraints, conditional updates, and provider idempotency are authoritative. Redis lock ownership or availability never authorizes money movement.
- Reserve whole positive earning entries. A nullable generated `active_ledger_entry_id` equals `ledger_entry_id` until confirmed release; `UNIQUE(active_ledger_entry_id)` is the final duplicate-live-reservation guard while released audit rows remain immutable.
- Dispatch jobs only after commit. Do not add an outbox unless a demonstrated dispatch-loss requirement appears.
- `UNKNOWN` and stale `PROCESSING` payouts retain reservations and can only be resolved by status reconciliation using the same idempotency key.
- Process discovery and recognition in deterministic bounded chunks; no application-level full-table scans.
- Log identifiers and hashed idempotency keys, never destination data, provider credentials, or unsanitized provider payloads.
- Use the existing application image for web, queue, scheduler, and test services; do not duplicate build logic.

## Review Focus

- Empty or duplicate instructor lists, non-positive weights, invalid basis points, invalid terms, and currency mismatch must be rejected before persistence; Task 3 pins these cases.
- Amounts smaller than the day or instructor count must still conserve every minor unit deterministically; Task 3 pins these cases.
- Duplicate payment/refund requests racing past Redis must converge on one durable MySQL result; Tasks 4 and 7 pin these cases.
- A worker crash or timeout after provider success must never produce a second transfer; Tasks 9 and 10 pin this case.
- Refunds after payout may create a carry-forward deficit but must not release, delete, or claw back a succeeded payout; Task 7 pins this case.

---

### Task 1: Reproducible Runtime and Installed UI Stack

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Create: `.dockerignore`
- Modify: `.env.example`
- Modify: `Dockerfile`
- Modify: `docker-compose.yml`
- Modify: `docker-compose.override.yml.example`
- Modify: `docker/entrypoint.sh`
- Modify: `config/horizon.php`
- Create: `app/Providers/Filament/AdminPanelProvider.php`
- Test: `tests/Feature/RuntimeConfigurationTest.php`

**Interfaces:**
- Consumes: the existing Laravel 11 skeleton and Docker files.
- Produces: one PHP 8.3 application image, healthy MySQL/Redis dependencies, `filament/filament:^3` and its Livewire/Alpine dependencies, and separate `recognition`, `payouts`, and `reconciliation` queues.

- [ ] **Step 1: Write the runtime configuration test**

Create Pest assertions named `it_uses_redis_for_production_queue_and_cache`, `it_keeps_financial_queues_separate`, and `it_registers_filament` that inspect configuration and the service container.

- [ ] **Step 2: Run the test to verify the missing Filament/queue setup fails**

Run: `docker compose run --rm test ./vendor/bin/pest tests/Feature/RuntimeConfigurationTest.php`

Expected: FAIL because Filament is not installed and the named queues are not configured.

- [ ] **Step 3: Finish the existing container setup and install Filament 3**

Reuse the existing Dockerfile and Compose services. Add only missing requirements: production-safe `.dockerignore`, non-secret environment examples, health-aware service startup, immutable production assets, graceful workers, and the Filament panel provider generated by Filament's installer.

- [ ] **Step 4: Verify the application image, infrastructure, and test runtime**

Run: `docker compose build app && docker compose up -d mysql redis app nginx && docker compose exec app php artisan about && docker compose run --rm test ./vendor/bin/pest tests/Feature/RuntimeConfigurationTest.php`

Expected: all services healthy, Laravel reports PHP 8.3/MySQL/Redis, and the test passes.

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock .dockerignore .env.example Dockerfile docker-compose.yml docker-compose.override.yml.example docker config tests/Feature/RuntimeConfigurationTest.php
git commit -m "chore: complete reproducible application runtime"
```

### Task 2: Financial Schema, Enums, Models, and Factories

**Files:**
- Create: `app/Domain/Ledger/LedgerEntryType.php`
- Create: `app/Domain/Payouts/PayoutStatus.php`
- Create: `app/Domain/Payouts/PayoutAttemptKind.php`
- Create: `app/Domain/Payouts/ProviderOutcome.php`
- Create: `app/Models/Subscription.php`
- Create: `app/Models/SubscriptionPayment.php`
- Create: `app/Models/PaymentInstructorShare.php`
- Create: `app/Models/RevenueScheduleItem.php`
- Create: `app/Models/InstructorLedgerEntry.php`
- Create: `app/Models/Refund.php`
- Create: `app/Models/RefundAllocation.php`
- Create: `app/Models/Payout.php`
- Create: `app/Models/PayoutItem.php`
- Create: `app/Models/PayoutAttempt.php`
- Create: `app/Models/InstructorBalanceSnapshot.php`
- Create: `app/Models/IdempotencyRecord.php`
- Create: `app/Models/MockProviderTransfer.php`
- Create: `database/migrations/2026_09_26_000001_create_financial_tables.php`
- Create: `database/factories/SubscriptionFactory.php`
- Create: `database/factories/SubscriptionPaymentFactory.php`
- Create: `database/factories/RevenueScheduleItemFactory.php`
- Create: `database/factories/InstructorLedgerEntryFactory.php`
- Create: `database/factories/RefundFactory.php`
- Create: `database/factories/PayoutFactory.php`
- Create: `database/factories/PayoutItemFactory.php`
- Create: `database/factories/PayoutAttemptFactory.php`
- Test: `tests/Feature/FinancialSchemaTest.php`

**Interfaces:**
- Consumes: Laravel Eloquent and MySQL 8.
- Produces: enum-cast Eloquent persistence models and database constraints/indexes used by all later tasks.

- [ ] **Step 1: Write failing schema invariant tests**

Test exact duplicate failures for payment provider reference, refund provider reference, ledger source key, payout idempotency key, active payout-item ledger entry, and payment/instructor pair. Test that a released item can be claimed later while its audit row remains. Test positive amounts/weights, valid basis points, valid terms, restrictive financial foreign keys, and the indexes listed in PRD section 9.

- [ ] **Step 2: Run the schema test to verify it fails**

Run: `docker compose run --rm test ./vendor/bin/pest tests/Feature/FinancialSchemaTest.php`

Expected: FAIL because financial tables and models do not exist.

- [ ] **Step 3: Add the minimum schema and persistence models**

Use ULIDs for externally visible payouts and BIGINT keys elsewhere. Add `refund_allocations(refund_id, schedule_item_id, instructor_id, kind, amount_minor, source_key)` so future cancellations and recognized adjustments remain append-only; make `source_key` unique. Give payout items `released_at` plus a generated `active_ledger_entry_id` that becomes `NULL` only on confirmed release, with `UNIQUE(active_ledger_entry_id)`. Add `manual_review_at` to payouts, `idempotency_records(scope, key, response_code, response_body)` with `UNIQUE(scope,key)`, and provider mock state with `UNIQUE(idempotency_key)`.

- [ ] **Step 4: Add model casts, relationships, and focused factories**

Keep business calculations out of Eloquent. Cast statuses/types to enums, structured snapshots/metadata to arrays, money fields to integers, and timestamps to immutable dates.

- [ ] **Step 5: Run migrations and schema tests**

Run: `docker compose run --rm test sh -lc 'php artisan migrate:fresh && ./vendor/bin/pest tests/Feature/FinancialSchemaTest.php'`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Domain app/Models database/migrations database/factories tests/Feature/FinancialSchemaTest.php
git commit -m "feat: add constrained financial schema"
```

### Task 3: Money, Allocation, and Recognition Schedule Rules

**Files:**
- Create: `app/Domain/Money/Money.php`
- Create: `app/Domain/Revenue/InstructorWeight.php`
- Create: `app/Domain/Revenue/AllocationResult.php`
- Create: `app/Domain/Revenue/RevenueAllocator.php`
- Create: `app/Domain/Revenue/ScheduledDay.php`
- Create: `app/Domain/Revenue/RecognitionScheduleBuilder.php`
- Test: `tests/Unit/Domain/Money/MoneyTest.php`
- Test: `tests/Unit/Domain/Revenue/RevenueAllocatorTest.php`
- Test: `tests/Unit/Domain/Revenue/RecognitionScheduleBuilderTest.php`

**Interfaces:**
- Consumes: integer minor units, ISO currency, platform basis points, instructor IDs/weights, and half-open UTC term dates.
- Produces: `Money::add(Money $other): Money`, `Money::subtract(Money $other): Money`, `RevenueAllocator::allocate(Money $payment, int $platformBps, array $weights): AllocationResult`, and `RecognitionScheduleBuilder::build(int $instructorPoolMinor, CarbonImmutable $start, CarbonImmutable $end): array<ScheduledDay>`.

- [ ] **Step 1: Write failing pure-domain tests**

Cover mismatched currencies, invalid ISO codes, negative arithmetic support, basis-point boundaries `0` and `10000`, empty/duplicate instructors, non-positive weights, amount smaller than instructor count, stable remainder tie-breaking, one-day/monthly/annual/leap-year terms, and amount smaller than day count. Assert conservation in every case.

- [ ] **Step 2: Run the unit tests to verify they fail**

Run: `docker compose run --rm test ./vendor/bin/pest tests/Unit/Domain`

Expected: FAIL because the domain classes do not exist.

- [ ] **Step 3: Implement the value objects and pure services**

Use only integer `intdiv`/modulo arithmetic. The allocator returns platform share, instructor pool, and instructor amounts; the schedule builder returns ascending dates and daily pools. Both throw `InvalidArgumentException` at invalid trust boundaries and assert conservation before returning.

- [ ] **Step 4: Run the pure-domain suite**

Run: `docker compose run --rm test ./vendor/bin/pest tests/Unit/Domain`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Money app/Domain/Revenue tests/Unit/Domain
git commit -m "feat: add deterministic revenue allocation"
```

### Task 4: Idempotent Payment Ingestion API and Schedule Persistence

**Files:**
- Create: `app/Application/Payments/RecordSubscriptionPayment.php`
- Create: `app/Http/Requests/RecordSubscriptionPaymentRequest.php`
- Create: `app/Http/Controllers/SubscriptionPaymentController.php`
- Create: `app/Http/Middleware/FinancialIdempotency.php`
- Create: `app/Jobs/RecognizeRevenue.php`
- Create: `routes/api.php`
- Modify: `bootstrap/app.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Payments/RecordSubscriptionPaymentTest.php`

**Interfaces:**
- Consumes: `POST /api/subscription-payments` with provider reference, subscription ID, positive minor amount, ISO currency, paid time, half-open term, platform basis points, and unique instructor weights; `Idempotency-Key` is required.
- Produces: `RecordSubscriptionPayment::handle(array $attributes): SubscriptionPayment`, `RecognizeRevenue::__construct(int $paymentId)`, and HTTP `201` for a new payment or the stored response for the same scoped idempotency key/provider reference.

- [ ] **Step 1: Write failing validation and idempotency tests**

Test valid persistence, schedule conservation, snapshotted shares, duplicate sequential requests, duplicate requests after clearing Redis, conflicting reuse of a provider reference, invalid term/basis points/currency/weights, and `Queue::assertPushedAfterResponse` or equivalent post-commit dispatch evidence.

- [ ] **Step 2: Run the payment feature test to verify it fails**

Run: `docker compose run --rm test ./vendor/bin/pest tests/Feature/Payments/RecordSubscriptionPaymentTest.php`

Expected: FAIL with missing route/action.

- [ ] **Step 3: Implement request validation and durable idempotency middleware**

Use Redis only as a fast response cache. Within MySQL, lock/create the `(scope,key)` record and rely on unique payment provider reference; return the originally stored status/body for repeats. Register a Redis-backed `financial` rate limiter and apply `throttle:financial` to payment/refund routes.

- [ ] **Step 4: Implement transactional payment and schedule persistence**

Persist payment, instructor snapshots, and all daily schedule items in one transaction. Chunk bulk inserts if needed. Dispatch recognition only with the payment ID and only after commit.

- [ ] **Step 5: Run the payment tests**

Run: `docker compose run --rm test ./vendor/bin/pest tests/Feature/Payments/RecordSubscriptionPaymentTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Application/Payments app/Http app/Jobs/RecognizeRevenue.php bootstrap/app.php app/Providers/AppServiceProvider.php routes/api.php tests/Feature/Payments
git commit -m "feat: ingest payments idempotently"
```

### Task 5: Idempotent Revenue Recognition and Ledger Balances

**Files:**
- Create: `app/Application/Ledger/CalculateInstructorBalance.php`
- Create: `app/Application/Ledger/RefreshInstructorBalanceSnapshot.php`
- Modify: `app/Jobs/RecognizeRevenue.php`
- Create: `app/Console/Commands/RecognizeRevenueCommand.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Revenue/RecognizeRevenueTest.php`
- Test: `tests/Feature/Ledger/CalculateInstructorBalanceTest.php`

**Interfaces:**
- Consumes: due `RevenueScheduleItem` rows and captured `PaymentInstructorShare` weights.
- Produces: `RecognizeRevenue::__construct(int $paymentId)`, `CalculateInstructorBalance::for(int $instructorId, string $currency, CarbonImmutable $asOf): array`, and `RefreshInstructorBalanceSnapshot::handle(int $instructorId, string $currency): InstructorBalanceSnapshot`.

- [ ] **Step 1: Write failing recognition and balance tests**

Test future days are ignored, due rows create one earning per instructor/day, duplicate jobs create no duplicate source keys, cancelled refund allocations reduce future recognition, gross/adjusted/net/paid/reserved/outstanding/deficit formulas are exact, and negative net carries forward.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose run --rm test ./vendor/bin/pest tests/Feature/Revenue tests/Feature/Ledger`

Expected: FAIL because jobs/actions do not exist.

- [ ] **Step 3: Implement bounded recognition**

Claim due schedule rows in indexed chunks, calculate each day's instructor shares from the snapshot, subtract append-only future cancellation allocations, and insert ledger rows with deterministic source keys. Mark schedule rows recognized only in the same transaction.

- [ ] **Step 4: Implement authoritative balance calculation and cached snapshots**

Aggregate only one instructor/currency pair at a time. Rebuild its MySQL snapshot after ledger/payout changes and invalidate/cache `balance:{instructor_id}:{currency}` only after commit.

- [ ] **Step 5: Register scheduled recognition and run tests**

Run: `docker compose run --rm test ./vendor/bin/pest tests/Feature/Revenue tests/Feature/Ledger`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Application/Ledger app/Jobs/RecognizeRevenue.php app/Console/Commands/RecognizeRevenueCommand.php routes/console.php tests/Feature/Revenue tests/Feature/Ledger
git commit -m "feat: recognize revenue into immutable ledger"
```

### Task 6: Payout State Machine

**Files:**
- Create: `app/Domain/Payouts/InvalidPayoutTransition.php`
- Create: `app/Domain/Payouts/PayoutStateMachine.php`
- Test: `tests/Unit/Domain/Payouts/PayoutStateMachineTest.php`

**Interfaces:**
- Consumes: current `PayoutStatus` and a requested transition event.
- Produces: `PayoutStateMachine::transition(PayoutStatus $from, PayoutStatus $to): PayoutStatus` and `PayoutStateMachine::isTerminal(PayoutStatus $status): bool`.

- [ ] **Step 1: Write the complete transition-table test**

Assert every PRD section 8 valid transition, every invalid transition, terminal idempotence, and that `UNKNOWN` never transitions to `PROCESSING` or `PENDING`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose run --rm test ./vendor/bin/pest tests/Unit/Domain/Payouts/PayoutStateMachineTest.php`

Expected: FAIL because the state machine does not exist.

- [ ] **Step 3: Implement the explicit transition table**

Keep it database-free; persistence actions must still use conditional SQL updates to stop stale workers.

- [ ] **Step 4: Run the test**

Run: `docker compose run --rm test ./vendor/bin/pest tests/Unit/Domain/Payouts/PayoutStateMachineTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Payouts tests/Unit/Domain/Payouts
git commit -m "feat: define payout state machine"
```

### Task 7: Append-Only Refund Processing

**Files:**
- Create: `app/Application/Refunds/RecordRefund.php`
- Create: `app/Http/Requests/RecordRefundRequest.php`
- Create: `app/Http/Controllers/RefundController.php`
- Modify: `routes/api.php`
- Test: `tests/Unit/Domain/Revenue/RefundAllocationTest.php`
- Test: `tests/Feature/Refunds/RecordRefundTest.php`

**Interfaces:**
- Consumes: `POST /api/refunds` with payment ID, unique provider refund reference, positive amount, UTC effective time, reason, and required `Idempotency-Key`.
- Produces: `RecordRefund::handle(array $attributes): Refund`, append-only `RefundAllocation` rows for future cancellations/recognized adjustments, and negative `REFUND_ADJUSTMENT` ledger entries for the recognized portion.

- [ ] **Step 1: Write failing refund allocation tests**

Test full mid-term, partial, repeated, cumulative-overpayment, effective date before/inside/after term, amount smaller than service/instructor counts, already-paid instructor deficit, and preservation of all original payment/schedule/ledger/payout rows.

- [ ] **Step 2: Run the refund tests to verify they fail**

Run: `docker compose run --rm test ./vendor/bin/pest tests/Unit/Domain/Revenue/RefundAllocationTest.php tests/Feature/Refunds/RecordRefundTest.php`

Expected: FAIL because refund processing does not exist.

- [ ] **Step 3: Implement deterministic proportional refund allocation**

Calculate the refundable instructor portion with the captured platform basis points. Apportion it over original daily pools, then instructors, using the same floor/largest-remainder rules. Write future portions as cancellation allocations and recognized portions as both allocation audit rows and negative ledger entries; never update/delete the originals.

- [ ] **Step 4: Add the idempotent endpoint and snapshot refresh**

Use the shared financial idempotency middleware, lock the payment while validating cumulative refunds, and refresh affected balance snapshots after commit.

- [ ] **Step 5: Run the refund tests**

Run: `docker compose run --rm test ./vendor/bin/pest tests/Unit/Domain/Revenue/RefundAllocationTest.php tests/Feature/Refunds/RecordRefundTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Application/Refunds app/Http routes/api.php tests/Unit/Domain/Revenue/RefundAllocationTest.php tests/Feature/Refunds
git commit -m "feat: add append-only refund adjustments"
```

### Task 8: Transactional Payout Reservation and Discovery Command

**Files:**
- Create: `app/Application/Payouts/CreateInstructorPayout.php`
- Create: `app/Console/Commands/CreateInstructorPayoutsCommand.php`
- Create: `config/ledger.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Payouts/CreateInstructorPayoutTest.php`
- Test: `tests/Feature/Commands/CreateInstructorPayoutsCommandTest.php`

**Interfaces:**
- Consumes: unreserved positive earning entries, net availability after adjustments/paid/reserved, per-currency threshold, destination snapshot, `--currency`, `--limit`, and `--dry-run`.
- Produces: `CreateInstructorPayout::handle(int $instructorId, string $currency): ?Payout` and `php artisan instructors:payout --currency=EGP --limit=1000 --dry-run`.

- [ ] **Step 1: Write failing reservation and command tests**

Test threshold, negative balance, whole-entry reservation, one payout per instructor/currency transaction, dry-run no writes/dispatch, repeat command, two overlapping creators, Redis lock expiry, active-reservation unique constraint fallback, deterministic chunking, and after-commit `ProcessInstructorPayout` dispatch.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose run --rm test ./vendor/bin/pest tests/Feature/Payouts/CreateInstructorPayoutTest.php tests/Feature/Commands/CreateInstructorPayoutsCommandTest.php`

Expected: FAIL because reservation action/command do not exist.

- [ ] **Step 3: Implement short MySQL reservation transactions**

Select candidate instructor/currency pairs through indexed grouped queries, lock only the selected ledger rows, re-check availability inside the transaction, create a ULID payout with one stable random idempotency key, and attach whole entries without exceeding outstanding. Catch a duplicate-key race as a skipped candidate, not a second claim.

- [ ] **Step 4: Implement discovery, dry-run, and owner-safe Redis coordination**

Use `payout-discovery:{currency}` as an optimization and commit/dispatch each instructor independently. The command never calls the provider.

- [ ] **Step 5: Run command/reservation tests**

Run: `docker compose run --rm test ./vendor/bin/pest tests/Feature/Payouts/CreateInstructorPayoutTest.php tests/Feature/Commands/CreateInstructorPayoutsCommandTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Application/Payouts app/Console/Commands/CreateInstructorPayoutsCommand.php config/ledger.php routes/console.php tests/Feature/Payouts tests/Feature/Commands
git commit -m "feat: reserve instructor payouts safely"
```

### Task 9: Idempotent Mock Provider and Payout Execution

**Files:**
- Create: `app/Infrastructure/Payments/PaymentProvider.php`
- Create: `app/Infrastructure/Payments/ProviderResult.php`
- Create: `app/Infrastructure/Payments/AmbiguousProviderException.php`
- Create: `app/Infrastructure/Payments/UnreliableMockPaymentProvider.php`
- Create: `app/Jobs/ProcessInstructorPayout.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Payouts/ProcessInstructorPayoutTest.php`

**Interfaces:**
- Consumes: `PaymentProvider::submitPayout(string $key, int $amountMinor, string $currency, array $destination): ProviderResult` and a payout ID.
- Produces: exactly one mock-provider transfer per idempotency key, audited submit attempts, and conditional local transitions to `SUCCEEDED`, `PERMANENTLY_FAILED`, or `UNKNOWN`.

- [ ] **Step 1: Write failing provider/execution tests**

Force success, permanent failure, timeout-after-success, repeated submit with the same key, duplicate job delivery, stale terminal retry, and crash-window recovery where provider state exists but local state remains `PROCESSING`. Assert sanitized attempt payloads and a transfer count of one.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose run --rm test ./vendor/bin/pest tests/Feature/Payouts/ProcessInstructorPayoutTest.php`

Expected: FAIL because provider/job do not exist.

- [ ] **Step 3: Implement the provider contract and deterministic mock**

Persist provider-side state by key before returning or throwing. Test configuration chooses `success`, `permanent_failure`, or `timeout_after_success`; random outcomes are allowed only behind a manual-demo flag.

- [ ] **Step 4: Implement the payout job**

Set bounded attempts/backoff/timeout. Claim `PENDING` conditionally, record an attempt, and submit with the persisted key/snapshots. Terminal records exit. Stale `PROCESSING` and ambiguous outcomes dispatch `CheckPayoutStatus`; confirmed failure releases reservations, while `UNKNOWN` never does.

- [ ] **Step 5: Run execution tests**

Run: `docker compose run --rm test ./vendor/bin/pest tests/Feature/Payouts/ProcessInstructorPayoutTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Infrastructure/Payments app/Jobs/ProcessInstructorPayout.php app/Providers/AppServiceProvider.php tests/Feature/Payouts/ProcessInstructorPayoutTest.php
git commit -m "feat: process payouts with provider idempotency"
```

### Task 10: Reconciliation and Operational Safety Sweep

**Files:**
- Create: `app/Jobs/CheckPayoutStatus.php`
- Create: `app/Console/Commands/ReconcileInstructorPayoutsCommand.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Payouts/CheckPayoutStatusTest.php`
- Test: `tests/Feature/Commands/ReconcileInstructorPayoutsCommandTest.php`

**Interfaces:**
- Consumes: `PaymentProvider::getPayoutStatus(string $key): ProviderResult`, payout ID, `--older-than=5m`, and `--limit=1000`.
- Produces: reconciled terminal state or retained reservation with bounded exponential retry; `php artisan instructors:reconcile-payouts` dispatches checks for stale `PROCESSING`/`UNKNOWN` payouts.

- [ ] **Step 1: Write failing reconciliation tests**

Test provider succeeded/failed/pending/unknown/not-found statuses, timeout-after-success convergence, no submit call during reconciliation, retained UNKNOWN reservations, released confirmed-failure reservations, stale PROCESSING discovery, manual-review flag after configured age, and repeated reconciliation idempotence.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose run --rm test ./vendor/bin/pest tests/Feature/Payouts/CheckPayoutStatusTest.php tests/Feature/Commands/ReconcileInstructorPayoutsCommandTest.php`

Expected: FAIL because reconciliation does not exist.

- [ ] **Step 3: Implement reconciliation job and state updates**

Use the existing payout key only. Apply conditional terminal transitions, retain reservations for unresolved outcomes, increment reconciliation count, set `manual_review_at` after the configured age, and refresh affected balance snapshots after terminal outcomes.

- [ ] **Step 4: Implement and schedule the safety sweep**

Select stale candidates through `(status,created_at)` indexes in bounded chunks and dispatch only IDs onto the dedicated reconciliation queue.

- [ ] **Step 5: Run reconciliation tests**

Run: `docker compose run --rm test ./vendor/bin/pest tests/Feature/Payouts/CheckPayoutStatusTest.php tests/Feature/Commands/ReconcileInstructorPayoutsCommandTest.php`

Expected: PASS and timeout-after-success transfer count remains one.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/CheckPayoutStatus.php app/Console/Commands/ReconcileInstructorPayoutsCommand.php routes/console.php tests/Feature/Payouts/CheckPayoutStatusTest.php tests/Feature/Commands/ReconcileInstructorPayoutsCommandTest.php
git commit -m "feat: reconcile uncertain payouts safely"
```

### Task 11: Read-Only Filament Financial Summary

**Files:**
- Create: `app/Filament/Resources/UserResource.php`
- Create: `app/Filament/Resources/UserResource/Pages/ViewInstructorFinancialSummary.php`
- Create: `app/Filament/Resources/UserResource/RelationManagers/PayoutsRelationManager.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Filament/InstructorFinancialSummaryTest.php`

**Interfaces:**
- Consumes: authorized instructor/user ID, balance snapshots/cache, and paginated payouts.
- Produces: one authorized, read-only Filament screen with per-currency cards and payout status/currency/date filters.

- [ ] **Step 1: Write failing Filament tests**

Test unauthorized denial, authorized access, exact formatted balance values, payout columns/filters, pagination, no edit/create/delete/bulk actions, no sensitive destination output, and a bounded query count without N+1 behavior.

- [ ] **Step 2: Run the UI tests to verify they fail**

Run: `docker compose run --rm test ./vendor/bin/pest tests/Feature/Filament/InstructorFinancialSummaryTest.php`

Expected: FAIL because the resource/page does not exist.

- [ ] **Step 3: Implement the minimum read-only resource**

Reuse the `User` model as the selected instructor identity for the take-home. Read cards from snapshots/cache, paginate payout history, expose only the PRD fields, and remove every mutating action.

- [ ] **Step 4: Run the UI tests**

Run: `docker compose run --rm test ./vendor/bin/pest tests/Feature/Filament/InstructorFinancialSummaryTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Filament app/Models/User.php tests/Feature/Filament
git commit -m "feat: add read-only instructor financial summary"
```

### Task 12: Demonstration Data, Operations Evidence, and Final Acceptance

**Files:**
- Create: `database/seeders/InstructorLedgerDemoSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Modify: `config/logging.php`
- Create: `app/Console/Commands/InstructorLedgerControlReportCommand.php`
- Modify: `routes/console.php`
- Create: `docs/runbook.md`
- Replace: `README.md`
- Test: `tests/Feature/Acceptance/FinancialInvariantsTest.php`
- Test: `tests/Feature/Acceptance/RedisFailureSafetyTest.php`

**Interfaces:**
- Consumes: all prior public actions, jobs, commands, and provider controls.
- Produces: reproducible demo data, redacted structured logs, operational recovery instructions, a daily control query/report, and acceptance evidence for AC01-AC16.

- [ ] **Step 1: Write end-to-end invariant tests**

Cover randomized allocation/schedule conservation, duplicate payments/refunds/jobs/commands, overlapping reservations on MySQL, Redis lock loss, provider timeout-after-success, confirmed failure re-reservation, UNKNOWN blocking, full/partial refund deficits, and the daily by-currency control totals.

- [ ] **Step 2: Run acceptance tests to expose remaining gaps**

Run: `docker compose run --rm test ./vendor/bin/pest tests/Feature/Acceptance`

Expected: FAIL until demo/operations integration and any uncovered acceptance gaps are complete.

- [ ] **Step 3: Add the demo seeder and safe operational output**

Seed one multi-instructor subscription and controllable payout cases without secrets. Add `php artisan instructors:ledger-control --date=YYYY-MM-DD` for recognized, adjusted, reserved, paid, and outstanding totals by currency. Structured logs and the report use IDs and a hash of the idempotency key only.

- [ ] **Step 4: Replace the skeleton README and add the runbook**

Document build/start/migrate/seed/Horizon/scheduler/test/teardown commands; assumptions; half-open term semantics; invariants; scale/index choices; provider scenarios; failure recovery; and the rule never to replace an UNKNOWN payout manually.

- [ ] **Step 5: Format and run the full verification suite twice**

Run: `docker compose run --rm app ./vendor/bin/pint --test`

Run: `docker compose run --rm test ./vendor/bin/pest && docker compose run --rm test ./vendor/bin/pest`

Run: `docker compose exec app php artisan migrate:fresh --seed && docker compose exec app php artisan instructors:payout --currency=EGP --limit=1000 --dry-run`

Expected: formatting clean; both Pest runs pass; seeding and dry-run complete; all AC01-AC16 are traceable to passing tests or the documented runtime demonstration.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/InstructorLedgerControlReportCommand.php routes/console.php database/seeders config/logging.php docs/runbook.md README.md tests/Feature/Acceptance
git commit -m "docs: complete ledger acceptance evidence"
```

## Deliberate Scope Cuts

- Use Laravel `afterCommit` dispatch instead of an outbox; add an outbox only if guaranteed broker handoff becomes an explicit requirement.
- Reserve whole ledger entries as the PRD permits; add partial payout allocation only if real payout caps require splitting an entry.
- Use `User` as the instructor identity and tokenized destination snapshot; authentication/KYC/bank onboarding remain outside scope.
- Provide only payment/refund ingestion endpoints and the read-only Filament screen; checkout, renewal, editable administration, and manual payout overrides remain outside scope.
- Use per-instructor/currency snapshot rebuilds, not a general event-projection framework; replace only if measured update throughput requires incremental projection.
