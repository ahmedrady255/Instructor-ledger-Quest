
**Instructor
Revenue Ledger Product Requirements Document**

**Laravel
11 financial core for subscription revenue allocation and instructor
payouts**

Version 1.0  |&#x20;
Prepared for engineering review  |  Stack: Laravel 11, MySQL, queues,
Filament 3, Livewire 3, Alpine.js, Pest

# 1 Executive Summary

This product is the
money core of an online course platform. It accepts paid subscription
events, recognizes revenue over the purchased access term, allocates
the instructor share deterministically, records immutable financial
entries, and pays instructors through an unreliable external provider
without duplicate transfers. The design assumes queue delivery is at
least once, workers may crash, commands may overlap, and provider
responses may be ambiguous.

The system's source
of truth is an append-only instructor ledger linked to immutable
payments, allocations, refunds, and payouts. Balances are derived
from ledger facts. Database constraints, transactions, stable
idempotency keys, provider-side idempotency, an explicit payout state
machine, and reconciliation together enforce correctness. A provider
timeout is never treated as a normal failure: it becomes an unknown
outcome that must be reconciled before any further transfer action.

# 2 Product Goals

- Allocate every received minor
  &#x9;currency unit exactly once between the platform and instructors.
- Answer earned, adjusted, paid,
  &#x9;reserved, and outstanding amounts for every instructor at any point
  &#x9;in time.
- Prevent duplicate payouts
  &#x9;during overlapping schedules, manual reruns, concurrent servers, and
  &#x9;job retries.
- Handle timeout-after-success
  &#x9;and other ambiguous provider outcomes without issuing a second
  &#x9;transfer.
- Support mid-term and partial
  &#x9;refunds through auditable compensating entries.
- Operate with 500,000 active
  &#x9;subscriptions and tens of millions of financial records.
- Expose a small read-only
  &#x9;Filament view for instructor balances and payout history.
- Provide meaningful automated
  &#x9;evidence for financial invariants and failure behavior.

# 3 Non Goals

- Student checkout, card
  &#x9;collection, tax calculation, invoicing, chargeback management, and
  &#x9;subscription renewal orchestration.
- A full instructor portal,
  &#x9;authentication implementation, bank onboarding, or KYC workflow.
- Multi-currency conversion. Each
  &#x9;payment, ledger stream, and payout remains in one currency;
  &#x9;conversion is outside scope.
- Real provider integration. The
  &#x9;deliverable includes a realistic mock behind a provider interface.
- Elaborate dashboards or
  &#x9;editable financial administration. Financial corrections use
  &#x9;explicit adjustment operations, not row editing.

# 4 Stakeholders and Users

|   |
| - |


&#x9;				**Actor**

|   |
| - |


&#x9;				**Need**

|   |
| - |


&#x9;				Finance
&#x9;				operations

|   |
| - |


&#x9;				Trustworthy
&#x9;				payable balances, payout outcomes, and an audit trail.

|   |
| - |


&#x9;				Instructor

|   |
| - |


&#x9;				Correct
&#x9;				earnings and payout history, with no duplicate or missing
&#x9;				payment.

|   |
| - |


&#x9;				Platform
&#x9;				engineering

|   |
| - |


&#x9;				Idempotent
&#x9;				APIs and jobs, observable failures, and recoverable workflows.

|   |
| - |


&#x9;				Support
&#x9;				and administrators

|   |
| - |


&#x9;				Read-only
&#x9;				visibility into balances and payout status.

|   |
| - |


&#x9;				Auditor
&#x9;				or reviewer

|   |
| - |


&#x9;				Trace
&#x9;				each amount from payment through allocation, ledger, and payout.



# 5 Assumptions and Product Decisions

|   |
| - |


&#x9;				**Decision**

|   |
| - |


&#x9;				**Chosen
&#x9;				rule**

|   |
| - |


&#x9;				**Reason**

|   |
| - |


&#x9;				Money
&#x9;				representation

|   |
| - |


&#x9;				Signed
&#x9;				BIGINT minor units plus ISO currency; no floating point.

|   |
| - |


&#x9;				Exact
&#x9;				arithmetic and explicit currency boundaries.

|   |
| - |


&#x9;				Revenue
&#x9;				recognition

|   |
| - |


&#x9;				Daily
&#x9;				over the paid access term in UTC.

|   |
| - |


&#x9;				Fair
&#x9;				handling of long prepaid terms and mid-term refunds.

|   |
| - |


&#x9;				Instructor
&#x9;				pool

|   |
| - |


&#x9;				Configured
&#x9;				basis points captured as a payment-time snapshot.

|   |
| - |


&#x9;				Historical
&#x9;				amounts do not change when commercial terms change.

|   |
| - |


&#x9;				Allocation
&#x9;				basis

|   |
| - |


&#x9;				Weighted
&#x9;				instructors captured for the subscription/payment; equal weights
&#x9;				by default.

|   |
| - |


&#x9;				Supports
&#x9;				multiple instructors while remaining deterministic.

|   |
| - |


&#x9;				Remainders

|   |
| - |


&#x9;				Largest-remainder
&#x9;				allocation with stable tie-break by instructor ID; daily
&#x9;				remainder assigned to earliest dates.

|   |
| - |


&#x9;				Conserves
&#x9;				every minor unit and gives repeatable results.

|   |
| - |


&#x9;				Refund
&#x9;				policy

|   |
| - |


&#x9;				Stop
&#x9;				future recognition from the effective date; create negative
&#x9;				adjustments for refunded recognized revenue.

|   |
| - |


&#x9;				Preserves
&#x9;				immutable history and avoids paying future unearned revenue.

|   |
| - |


&#x9;				Negative
&#x9;				balances

|   |
| - |


&#x9;				Carry
&#x9;				forward against future earnings; do not initiate automatic
&#x9;				clawbacks.

|   |
| - |


&#x9;				Avoids
&#x9;				unsafe debit behavior while keeping liability accurate.

|   |
| - |


&#x9;				Payout
&#x9;				threshold

|   |
| - |


&#x9;				Configurable
&#x9;				per currency; default zero for challenge tests.

|   |
| - |


&#x9;				Avoids
&#x9;				hard-coded commercial policy.

|   |
| - |


&#x9;				Payout
&#x9;				cadence

|   |
| - |


&#x9;				Scheduled
&#x9;				command discovers and reserves eligible ledger entries.

|   |
| - |


&#x9;				Separates
&#x9;				deterministic DB work from external side effects.

|   |
| - |


&#x9;				Unknown
&#x9;				provider outcome

|   |
| - |


&#x9;				Reconcile
&#x9;				by the same idempotency key; never create a new logical payout.

|   |
| - |


&#x9;				Prevents
&#x9;				duplicate movement after timeout-after-success.



# 6 Domain Model

The core flow is
Payment to Revenue Recognition to Allocation to Immutable Ledger to
Payout to Provider Reconciliation. Refunds introduce compensating
ledger entries and may cancel future recognition. No single aggregate
owns the entire flow; application services coordinate transactions
across bounded concerns.

|   |
| - |


&#x9;				**Aggregate
&#x9;				or object**

|   |
| - |


&#x9;				**Responsibility**

|   |
| - |


&#x9;				**Key
&#x9;				invariants**

|   |
| - |


&#x9;				Subscription

|   |
| - |


&#x9;				Purchased
&#x9;				access period, plan, status, and instructors/weights.

|   |
| - |


&#x9;				Start
&#x9;				precedes end; one active paid term represented consistently.

|   |
| - |


&#x9;				SubscriptionPayment

|   |
| - |


&#x9;				Immutable
&#x9;				record of money received and commercial snapshots.

|   |
| - |


&#x9;				Provider
&#x9;				reference is unique; amount is positive; currency is fixed.

|   |
| - |


&#x9;				RevenueSchedule

|   |
| - |


&#x9;				Daily
&#x9;				recognition schedule for one payment.

|   |
| - |


&#x9;				Scheduled
&#x9;				instructor pool equals payment-time instructor share.

|   |
| - |


&#x9;				RevenueAllocation

|   |
| - |


&#x9;				Pure
&#x9;				deterministic split across platform and instructors.

|   |
| - |


&#x9;				All
&#x9;				shares sum exactly to the payment amount.

|   |
| - |


&#x9;				InstructorLedger

|   |
| - |


&#x9;				Append-only
&#x9;				earning and adjustment facts.

|   |
| - |


&#x9;				Natural
&#x9;				source key prevents duplicated recognition or refund entries.

|   |
| - |


&#x9;				Payout

|   |
| - |


&#x9;				One
&#x9;				logical instruction to transfer money to one instructor in one
&#x9;				currency.

|   |
| - |


&#x9;				Stable
&#x9;				idempotency key for its lifetime; terminal success cannot
&#x9;				reopen.

|   |
| - |


&#x9;				PayoutItem

|   |
| - |


&#x9;				Reservation
&#x9;				of a payable ledger amount into a payout.

|   |
| - |


&#x9;				One
&#x9;				positive ledger entry or remaining portion is reserved once.

|   |
| - |


&#x9;				PayoutAttempt

|   |
| - |


&#x9;				Audit
&#x9;				of provider submission and status interactions.

|   |
| - |


&#x9;				Attempt
&#x9;				history never defines a second logical payout.

|   |
| - |


&#x9;				Refund

|   |
| - |


&#x9;				Immutable
&#x9;				received refund event and allocation adjustments.

|   |
| - |


&#x9;				Provider
&#x9;				refund reference is unique; cumulative refund cannot exceed
&#x9;				payment.

|   |
| - |


&#x9;				Money

|   |
| - |


&#x9;				Value
&#x9;				object holding integer minor units and currency.

|   |
| - |


&#x9;				Arithmetic
&#x9;				requires matching currency; multiplication uses integer rules.



# 7 Functional Requirements

## 7.1 Payment ingestion

1. The system shall accept a
   &#x9;successful subscription payment with a unique provider reference,
   &#x9;subscription ID, positive amount in minor units, currency, paid
   &#x9;time, term start, term end, platform share in basis points, and
   &#x9;instructor weights.
2. Repeated ingestion of the same
   &#x9;provider reference shall return the existing result without creating
   &#x9;duplicate schedules, allocations, or ledger entries.
3. Payment ingestion shall
   &#x9;snapshot all rules needed to reproduce the allocation: platform
   &#x9;basis points, term boundaries, instructor IDs, and weights.
4. The transaction shall persist
   &#x9;the payment and recognition schedule before asynchronous recognition
   &#x9;jobs are dispatched.

## 7.2 Revenue allocation and recognition

5. The allocator shall calculate
   &#x9;the platform share and instructor pool using integer arithmetic
   &#x9;only.
6. The recognition schedule shall
   &#x9;divide the instructor pool over service days. Any daily remainder
   &#x9;shall be distributed one minor unit at a time from the earliest
   &#x9;date.
7. Each day's instructor pool
   &#x9;shall be divided by captured instructor weights using floor shares,
   &#x9;followed by largest remainders with instructor ID as a stable
   &#x9;tie-break.
8. Recognition shall create
   &#x9;idempotent ledger entries only when a service day becomes earned.
   &#x9;The unique source identity shall be payment, service date,
   &#x9;instructor, and entry type.
9. Recognition may run in batches
   &#x9;and may be retried without changing previous financial results.

## 7.3 Balance calculation

For each instructor
and currency, the system shall expose:

|   |
| - |


&#x9;				**Measure**

|   |
| - |


&#x9;				**Definition**

|   |
| - |


&#x9;				Gross
&#x9;				earned

|   |
| - |


&#x9;				Sum
&#x9;				of positive EARNING entries whose earned date has passed.

|   |
| - |


&#x9;				Adjustments

|   |
| - |


&#x9;				Sum
&#x9;				of negative REFUND_ADJUSTMENT and manual correction entries.

|   |
| - |


&#x9;				Net
&#x9;				earned

|   |
| - |


&#x9;				Gross
&#x9;				earned plus adjustments.

|   |
| - |


&#x9;				Paid

|   |
| - |


&#x9;				Sum
&#x9;				of amounts attached to SUCCEEDED payouts.

|   |
| - |


&#x9;				Reserved

|   |
| - |


&#x9;				Amounts
&#x9;				attached to nonterminal payouts that are not permanently failed.

|   |
| - |


&#x9;				Outstanding

|   |
| - |


&#x9;				Maximum
&#x9;				of zero and net earned minus paid minus reserved.

|   |
| - |


&#x9;				Carry-forward
&#x9;				deficit

|   |
| - |


&#x9;				Absolute
&#x9;				value when net earned minus paid is negative; offset future
&#x9;				earnings.



## 7.4 Payout creation

10. The Artisan command
    &#x9;instructors\:payout shall discover instructors with eligible
    &#x9;outstanding amounts, grouped by currency.
11. The command shall create payout
    &#x9;records and reserve eligible ledger amounts inside short MySQL
    &#x9;transactions. It shall not call the provider.
12. Rows shall be selected in
    &#x9;bounded batches using indexed queries and locking. MySQL 8 FOR
    &#x9;UPDATE SKIP LOCKED may reduce contention.
13. A unique reservation constraint
    &#x9;shall prevent the same payable source amount from belonging to two
    &#x9;live or successful payouts.
14. After commit, the command shall
    &#x9;dispatch ProcessInstructorPayout with only the payout ID.
15. Running the command
    &#x9;concurrently or repeatedly shall not create an additional payable
    &#x9;claim over already-reserved entries.

## 7.5 Payout execution

16. The job shall load the payout
    &#x9;and exit successfully when it is already SUCCEEDED or CANCELLED.
17. A PENDING payout may transition
    &#x9;to PROCESSING under a conditional database update or row lock.
18. The provider request shall
    &#x9;always use the persisted payout idempotency key and exact amount,
    &#x9;currency, and destination snapshot.
19. Provider success shall
    &#x9;transition the payout to SUCCEEDED and record the provider transfer
    &#x9;reference.
20. A confirmed permanent rejection
    &#x9;shall transition to PERMANENTLY_FAILED and release its reservations
    &#x9;for future payout creation.
21. A transport timeout or
    &#x9;ambiguous exception shall transition to UNKNOWN and dispatch
    &#x9;reconciliation. It shall not release reservations or submit another
    &#x9;new transfer.
22. Unexpected worker termination
    &#x9;after provider interaction shall be recoverable by checking provider
    &#x9;status with the stable idempotency key before attempting submission
    &#x9;again.

## 7.6 Reconciliation

23. CheckPayoutStatus shall query
    &#x9;provider status for PROCESSING or UNKNOWN payouts using the existing
    &#x9;idempotency key.
24. Provider SUCCEEDED shall make
    &#x9;the local payout SUCCEEDED without another transfer call.
25. Provider PERMANENTLY_FAILED
    &#x9;shall make the local payout PERMANENTLY_FAILED and release
    &#x9;reservations.
26. Provider PENDING or UNKNOWN
    &#x9;shall keep reservations and retry status checks with bounded
    &#x9;exponential backoff.
27. After a configurable
    &#x9;reconciliation age, the system shall flag the payout for manual
    &#x9;review while continuing to block a replacement transfer.

## 7.7 Refunds

28. A refund shall be ingested
    &#x9;idempotently using its provider refund reference.
29. Cumulative refunds shall never
    &#x9;exceed the original payment amount.
30. A full mid-term refund shall
    &#x9;cancel unearned future schedule items from the effective date and
    &#x9;allocate the refundable recognized portion as negative ledger
    &#x9;adjustments.
31. A partial refund shall be
    &#x9;allocated proportionally across unearned and, when necessary,
    &#x9;recognized revenue using the same deterministic remainder rules.
32. Original payments, schedules,
    &#x9;and ledger entries shall not be modified or deleted; corrections
    &#x9;shall be appended and linked to the refund.
33. If an adjustment produces a
    &#x9;negative instructor position, the deficit shall offset future
    &#x9;earnings. Automatic withdrawal from the instructor is outside scope.

# 8 Payout State Machine

|   |
| - |


&#x9;				**Current
&#x9;				state**

|   |
| - |


&#x9;				**Event**

|   |
| - |


&#x9;				**Next
&#x9;				state**

|   |
| - |


&#x9;				**Side
&#x9;				effect**

|   |
| - |


&#x9;				PENDING

|   |
| - |


&#x9;				Worker
&#x9;				claims

|   |
| - |


&#x9;				PROCESSING

|   |
| - |


&#x9;				Create
&#x9;				attempt record.

|   |
| - |


&#x9;				PROCESSING

|   |
| - |


&#x9;				Provider
&#x9;				succeeds

|   |
| - |


&#x9;				SUCCEEDED

|   |
| - |


&#x9;				Store
&#x9;				provider reference and completed time.

|   |
| - |


&#x9;				PROCESSING

|   |
| - |


&#x9;				Permanent
&#x9;				rejection

|   |
| - |


&#x9;				PERMANENTLY_FAILED

|   |
| - |


&#x9;				Release
&#x9;				reservations.

|   |
| - |


&#x9;				PROCESSING

|   |
| - |


&#x9;				Timeout
&#x9;				or ambiguity

|   |
| - |


&#x9;				UNKNOWN

|   |
| - |


&#x9;				Schedule
&#x9;				status reconciliation.

|   |
| - |


&#x9;				UNKNOWN

|   |
| - |


&#x9;				Status
&#x9;				says succeeded

|   |
| - |


&#x9;				SUCCEEDED

|   |
| - |


&#x9;				No
&#x9;				transfer call.

|   |
| - |


&#x9;				UNKNOWN

|   |
| - |


&#x9;				Status
&#x9;				says failed

|   |
| - |


&#x9;				PERMANENTLY_FAILED

|   |
| - |


&#x9;				Release
&#x9;				reservations.

|   |
| - |


&#x9;				UNKNOWN

|   |
| - |


&#x9;				Status
&#x9;				pending/unknown

|   |
| - |


&#x9;				UNKNOWN

|   |
| - |


&#x9;				Back
&#x9;				off and check again.

|   |
| - |


&#x9;				SUCCEEDED

|   |
| - |


&#x9;				Any
&#x9;				retry

|   |
| - |


&#x9;				SUCCEEDED

|   |
| - |


&#x9;				No
&#x9;				operation.

|   |
| - |


&#x9;				PERMANENTLY_FAILED

|   |
| - |


&#x9;				Any
&#x9;				stale retry

|   |
| - |


&#x9;				PERMANENTLY_FAILED

|   |
| - |


&#x9;				No
&#x9;				provider call.



Only reconciliation
may resolve UNKNOWN. PROCESSING records older than a safety threshold
are treated as uncertain and reconciled before any submission is
considered. Terminal transitions are enforced in the domain and with
conditional updates.

# 9 Data Model and Migrations

All timestamps are
UTC. Primary keys may be BIGINT or UUID/ULID; ULID is preferred for
externally visible payout IDs. Foreign keys use restrictive deletion
for financial tables. No cascade delete may remove financial history.

|   |
| - |


&#x9;				**Table**

|   |
| - |


&#x9;				**Essential
&#x9;				columns**

|   |
| - |


&#x9;				**Critical
&#x9;				constraints and indexes**

|   |
| - |


&#x9;				subscriptions

|   |
| - |


&#x9;				id,
&#x9;				student_id, plan, starts_at, ends_at, status

|   |
| - |


&#x9;				INDEX(status,
&#x9;				ends_at); CHECK starts_at < ends_at

|   |
| - |


&#x9;				subscription_payments

|   |
| - |


&#x9;				id,
&#x9;				subscription_id, provider_reference, amount_minor, currency,
&#x9;				paid_at, platform_bps, term_start, term_end

|   |
| - |


&#x9;				UNIQUE(provider_reference);
&#x9;				INDEX(subscription_id, paid_at); amount_minor > 0

|   |
| - |


&#x9;				payment_instructor_shares

|   |
| - |


&#x9;				payment_id,
&#x9;				instructor_id, weight, stable_order

|   |
| - |


&#x9;				UNIQUE(payment_id,
&#x9;				instructor_id); weight > 0

|   |
| - |


&#x9;				revenue_schedule_items

|   |
| - |


&#x9;				id,
&#x9;				payment_id, service_date, instructor_pool_minor, status,
&#x9;				recognized_at

|   |
| - |


&#x9;				UNIQUE(payment_id,
&#x9;				service_date); INDEX(status, service_date)

|   |
| - |


&#x9;				instructor_ledger_entries

|   |
| - |


&#x9;				id,
&#x9;				instructor_id, payment_id, refund_id nullable, schedule_item_id
&#x9;				nullable, type, amount_minor, currency, earned_at, source_key,
&#x9;				metadata

|   |
| - |


&#x9;				UNIQUE(source_key);
&#x9;				INDEX(instructor_id, currency, earned_at); INDEX(type,
&#x9;				earned_at)

|   |
| - |


&#x9;				refunds

|   |
| - |


&#x9;				id,
&#x9;				payment_id, provider_reference, amount_minor, effective_at,
&#x9;				reason

|   |
| - |


&#x9;				UNIQUE(provider_reference);
&#x9;				INDEX(payment_id, effective_at)

|   |
| - |


&#x9;				payouts

|   |
| - |


&#x9;				id,
&#x9;				instructor_id, idempotency_key, amount_minor, currency,
&#x9;				destination_snapshot, status, provider_reference, submitted_at,
&#x9;				completed_at, failed_at, reconciliation_count

|   |
| - |


&#x9;				UNIQUE(idempotency_key);
&#x9;				INDEX(status, created_at); INDEX(instructor_id, currency,
&#x9;				status)

|   |
| - |


&#x9;				payout_items

|   |
| - |


&#x9;				id,
&#x9;				payout_id, ledger_entry_id, amount_minor

|   |
| - |


&#x9;				UNIQUE(ledger_entry_id);
&#x9;				INDEX(payout_id); amount_minor > 0

|   |
| - |


&#x9;				payout_attempts

|   |
| - |


&#x9;				id,
&#x9;				payout_id, kind, attempt_no, status, request_id, response_code,
&#x9;				response_payload, started_at, finished_at

|   |
| - |


&#x9;				UNIQUE(payout_id,
&#x9;				kind, attempt_no); INDEX(payout_id, started_at)

|   |
| - |


&#x9;				instructor_balance_snapshots

|   |
| - |


&#x9;				instructor_id,
&#x9;				currency, earned_minor, adjusted_minor, paid_minor,
&#x9;				reserved_minor, outstanding_minor, as_of

|   |
| - |


&#x9;				PRIMARY
&#x9;				KEY(instructor_id, currency); optimization only

|   |
| - |


&#x9;				outbox_messages

|   |
| - |


&#x9;				id,
&#x9;				type, aggregate_id, payload, available_at, dispatched_at

|   |
| - |


&#x9;				INDEX(dispatched_at,
&#x9;				available_at); optional transactional dispatch reliability



If partial
reservation of a ledger entry is needed, replace
UNIQUE(ledger_entry_id) with a payout allocation table plus
transactional remaining-amount validation. For this challenge, ledger
entries should be sufficiently granular so each entry is reserved
whole, making the unique constraint simple and strong.

# 10 Allocation Algorithm

## 10.1 Platform and instructor pool


platform_share
\= floor(payment_minor \* platform_bps / 10_000)

instructor_pool =
payment_minor - platform_share

## 10.2 Daily recognition


base_daily
\= floor(instructor_pool / service_day_count)

remainder =
instructor_pool mod service_day_count



for each service
date in ascending order:

&#x20;   daily_pool = base_daily + (1 if
date_index < remainder else 0)

## 10.3 Weighted instructor split


exact
numerator[i] = daily_pool \* weight[i]

floor_share[i] =
floor(numerator[i] / total_weight)

units_left = daily_pool -
sum(floor_share)



rank by (numerator[i] mod total_weight)
descending, instructor_id ascending

add one minor unit to the
first units_left instructors

The allocator is a
pure domain service. The same input always produces the same output.
It returns an AllocationResult that contains platform share,
instructor pool, and per-instructor allocations. It must assert
conservation before returning.

# 11 Application Components

|   |
| - |


&#x9;				**Component**

|   |
| - |


&#x9;				**Type**

|   |
| - |


&#x9;				**Responsibility**

|   |
| - |


&#x9;				Money

|   |
| - |


&#x9;				Value
&#x9;				object

|   |
| - |


&#x9;				Currency-safe
&#x9;				integer arithmetic.

|   |
| - |


&#x9;				RevenueAllocator

|   |
| - |


&#x9;				Domain
&#x9;				service

|   |
| - |


&#x9;				Pure
&#x9;				deterministic platform and instructor split.

|   |
| - |


&#x9;				RecognitionScheduleBuilder

|   |
| - |


&#x9;				Domain
&#x9;				service

|   |
| - |


&#x9;				Creates
&#x9;				deterministic daily schedule.

|   |
| - |


&#x9;				RecordSubscriptionPayment

|   |
| - |


&#x9;				Application
&#x9;				action

|   |
| - |


&#x9;				Idempotent
&#x9;				payment ingestion and snapshot persistence.

|   |
| - |


&#x9;				RecognizeRevenue

|   |
| - |


&#x9;				Queued
&#x9;				job/action

|   |
| - |


&#x9;				Appends
&#x9;				due earning entries in batches.

|   |
| - |


&#x9;				RecordRefund

|   |
| - |


&#x9;				Application
&#x9;				action

|   |
| - |


&#x9;				Validates
&#x9;				refund, cancels future schedule, appends adjustments.

|   |
| - |


&#x9;				CreateInstructorPayout

|   |
| - |


&#x9;				Application
&#x9;				action

|   |
| - |


&#x9;				Locks
&#x9;				and reserves eligible entries transactionally.

|   |
| - |


&#x9;				ProcessInstructorPayout

|   |
| - |


&#x9;				Queued
&#x9;				job

|   |
| - |


&#x9;				Submits
&#x9;				or reconciles one logical payout safely.

|   |
| - |


&#x9;				CheckPayoutStatus

|   |
| - |


&#x9;				Queued
&#x9;				job

|   |
| - |


&#x9;				Resolves
&#x9;				uncertain provider outcome without resubmission.

|   |
| - |


&#x9;				PaymentProvider

|   |
| - |


&#x9;				Interface

|   |
| - |


&#x9;				submitPayout
&#x9;				and getPayoutStatus contracts.

|   |
| - |


&#x9;				UnreliableMockPaymentProvider

|   |
| - |


&#x9;				Test/demo
&#x9;				adapter

|   |
| - |


&#x9;				Deterministically
&#x9;				or randomly simulates required outcomes.

|   |
| - |


&#x9;				instructors\:payout

|   |
| - |


&#x9;				Artisan
&#x9;				command

|   |
| - |


&#x9;				Discovers
&#x9;				candidates, creates payouts, dispatches jobs.

|   |
| - |


&#x9;				instructors\:reconcile-payouts

|   |
| - |


&#x9;				Artisan
&#x9;				command

|   |
| - |


&#x9;				Safety
&#x9;				sweep for stale PROCESSING and UNKNOWN payouts.



# 12 Mock Payment Provider

The mock provider
must own provider-side transfer state keyed by the payout idempotency
key. Tests must be able to force an outcome; random behavior may be
enabled only for manual demonstration. The three required submission
outcomes are success, permanent failure, and timeout after success.

|   |
| - |


&#x9;				**Scenario**

|   |
| - |


&#x9;				**Provider
&#x9;				behavior**

|   |
| - |


&#x9;				**Local
&#x9;				behavior**

|   |
| - |


&#x9;				Success

|   |
| - |


&#x9;				Persist
&#x9;				successful transfer once and return success/reference.

|   |
| - |


&#x9;				Mark
&#x9;				SUCCEEDED.

|   |
| - |


&#x9;				Permanent
&#x9;				failure

|   |
| - |


&#x9;				Persist
&#x9;				terminal failure and return rejection.

|   |
| - |


&#x9;				Mark
&#x9;				PERMANENTLY_FAILED and release reservation.

|   |
| - |


&#x9;				Timeout
&#x9;				after success

|   |
| - |


&#x9;				Persist
&#x9;				successful transfer, then throw timeout.

|   |
| - |


&#x9;				Mark
&#x9;				UNKNOWN, retain reservation, reconcile status.

|   |
| - |


&#x9;				Repeated
&#x9;				submit with same key

|   |
| - |


&#x9;				Return
&#x9;				the original stored result without moving money again.

|   |
| - |


&#x9;				Converge
&#x9;				to stored status.

|   |
| - |


&#x9;				Status
&#x9;				lookup

|   |
| - |


&#x9;				Return
&#x9;				stored succeeded, failed, pending, or not found.

|   |
| - |


&#x9;				Apply
&#x9;				state transition; do not create a new key.



# 13 Concurrency and Idempotency Design

- Payment ingestion: unique
  &#x9;provider_reference and a transaction make duplicate webhooks
  &#x9;harmless.
- Recognition: unique source_key
  &#x9;makes each instructor/date/payment entry append once.
- Payout reservation: unique
  &#x9;payout_items.ledger_entry_id and row locking prevent two payouts
  &#x9;from claiming the same earning.
- Payout identity: one persisted
  &#x9;idempotency_key per logical payout, reused across every job retry
  &#x9;and provider interaction.
- State transitions: conditional
  &#x9;updates ensure stale workers cannot overwrite terminal outcomes.
- Provider uncertainty: UNKNOWN
  &#x9;retains reservations; status reconciliation is mandatory before any
  &#x9;transfer behavior.
- Queue duplication: jobs contain
  &#x9;IDs, reload current state, and are safe under at-least-once
  &#x9;execution.
- Dispatch-after-commit: jobs are
  &#x9;dispatched only after transaction commit; an outbox may be used for
  &#x9;stronger dispatch reliability.
- Locks are performance aids, not
  &#x9;the only correctness layer. Unique constraints remain the final
  &#x9;guard.

## 13.1 Redis coordination and caching

Redis is part of the
production architecture, but it is not the financial source of truth.
It reduces duplicate work, powers queues, and accelerates reads.
MySQL remains authoritative for business idempotency, ledger
balances, payout ownership, and state transitions; the payment
provider's stable idempotency key protects the external transfer.

|   |
| - |


&#x9;				**Redis
&#x9;				capability**

|   |
| - |


&#x9;				**Use**

|   |
| - |


&#x9;				**Durable
&#x9;				fallback or authority**

|   |
| - |


&#x9;				Distributed
&#x9;				locks

|   |
| - |


&#x9;				Coordinate
&#x9;				payout discovery by currency, one payout worker, earning-period
&#x9;				finalization, subscription upgrades, refunds, and balance
&#x9;				rebuilds.

|   |
| - |


&#x9;				MySQL
&#x9;				row locks, conditional updates, and unique constraints still
&#x9;				enforce correctness.

|   |
| - |


&#x9;				Queue
&#x9;				backend

|   |
| - |


&#x9;				Run
&#x9;				payout, reconciliation, recognition, and balance-projection
&#x9;				queues with Horizon.

|   |
| - |


&#x9;				Jobs
&#x9;				reload authoritative state and are safe under at-least-once
&#x9;				execution.

|   |
| - |


&#x9;				Idempotent
&#x9;				response cache

|   |
| - |


&#x9;				Return
&#x9;				a prior HTTP result quickly for repeated upgrade, payment, or
&#x9;				refund requests.

|   |
| - |


&#x9;				A
&#x9;				MySQL idempotency_records row with a unique scoped key is
&#x9;				authoritative.

|   |
| - |


&#x9;				Balance
&#x9;				cache

|   |
| - |


&#x9;				Cache
&#x9;				the MySQL balance snapshot used by Filament and APIs.

|   |
| - |


&#x9;				Immutable
&#x9;				ledger and MySQL balance snapshot can rebuild the cached value.

|   |
| - |


&#x9;				Rate
&#x9;				limiting

|   |
| - |


&#x9;				Throttle
&#x9;				financial endpoints, webhooks, and reconciliation requests.

|   |
| - |


&#x9;				Business
&#x9;				validation and financial constraints remain in MySQL.

|   |
| - |


&#x9;				Horizon
&#x9;				telemetry

|   |
| - |


&#x9;				Observe
&#x9;				throughput, failures, retries, and queue wait time.

|   |
| - |


&#x9;				Payout
&#x9;				attempts and outcomes remain persisted in MySQL.



- Use owner-safe locks with
  &#x9;bounded expiration. Losing or expiring a lock must never authorize a
  &#x9;second logical transfer.
- Recommended keys include
  &#x9;payout-discovery:{currency}, payout-processing:{payout_id},
  &#x9;earning-period:{period_id}, subscription-upgrade:{subscription_id},
  &#x9;and balance:{instructor_id}:{currency}.
- Do not place bank details,
  &#x9;provider credentials, or other sensitive data in Redis keys or
  &#x9;values.
- Invalidate balance caches only
  &#x9;after the related MySQL transaction commits.
- Use separate Redis connections
  &#x9;or instances for cache, queues, and locks in production; logical
  &#x9;databases alone do not provide resource isolation.
- Critical reconciliation queues
  &#x9;must remain separate from large recognition and analytics workloads.

# 14 Artisan Command and Queue Requirements


php
artisan instructors\:payout --currency=EGP --limit=1000 --dry-run

php
artisan instructors\:reconcile-payouts --older-than=5m --limit=1000

- The payout command must support
  &#x9;dry-run reporting without inserts or dispatch.
- Candidate processing is chunked
  &#x9;and deterministic. It must not load all instructors or ledger rows
  &#x9;into memory.
- Each instructor/currency payout
  &#x9;is committed independently so one failure does not roll back an
  &#x9;entire run.
- Jobs use bounded attempts,
  &#x9;backoff, timeout settings, and failed-job reporting. Business
  &#x9;permanent failures are recorded states, not endlessly retried
  &#x9;exceptions.
- Reconciliation has a higher
  &#x9;retry horizon than submission because an ambiguous transfer must
  &#x9;remain protected until resolved.

## 14.1 Containerized development and runtime

The first
implementation deliverable is a reproducible Docker environment. One
immutable application image is reused by the web, queue, scheduler,
and test containers so local development and automated testing run
with the same PHP extensions and system dependencies.

|   |
| - |


&#x9;				**Service
&#x9;				or build stage**

|   |
| - |


&#x9;				**Required
&#x9;				runtime**

|   |
| - |


&#x9;				**Responsibility**

|   |
| - |


&#x9;				app

|   |
| - |


&#x9;				PHP
&#x9;				8.3 FPM with Composer 2

|   |
| - |


&#x9;				Laravel
&#x9;				HTTP runtime and Artisan commands.

|   |
| - |


&#x9;				nginx

|   |
| - |


&#x9;				Stable
&#x9;				Nginx Alpine image

|   |
| - |


&#x9;				Public
&#x9;				entry point and FastCGI proxy to the app container.

|   |
| - |


&#x9;				queue

|   |
| - |


&#x9;				Same
&#x9;				application image

|   |
| - |


&#x9;				Laravel
&#x9;				queue worker or Horizon with pcntl enabled.

|   |
| - |


&#x9;				scheduler

|   |
| - |


&#x9;				Same
&#x9;				application image

|   |
| - |


&#x9;				Runs
&#x9;				schedule\:work; triggers recognition, payout discovery, and
&#x9;				reconciliation schedules.

|   |
| - |


&#x9;				mysql

|   |
| - |


&#x9;				MySQL
&#x9;				8.0

|   |
| - |


&#x9;				Authoritative
&#x9;				ledger, idempotency, payout, subscription, and audit data.

|   |
| - |


&#x9;				redis

|   |
| - |


&#x9;				Redis
&#x9;				7 Alpine

|   |
| - |


&#x9;				Queues,
&#x9;				locks, cache, rate limiting, and Horizon state.

|   |
| - |


&#x9;				assets

|   |
| - |


&#x9;				Node.js
&#x9;				20 LTS build stage

|   |
| - |


&#x9;				Installs
&#x9;				frontend dependencies and builds Vite, Filament, Livewire, and
&#x9;				Alpine assets.

|   |
| - |


&#x9;				test

|   |
| - |


&#x9;				Same
&#x9;				application image plus test configuration

|   |
| - |


&#x9;				Runs
&#x9;				migrations and the Pest suite against MySQL and Redis.



The PHP image shall
install pdo_mysql, bcmath, intl, mbstring, opcache, pcntl, zip, curl,
and the phpredis extension. It shall include Composer dependencies,
copy the application with production-safe permissions, expose
PHP-FPM, and use a non-root runtime user where practical. Multi-stage
builds shall keep Node and build-only packages out of the final PHP
runtime image.

- docker-compose.yml shall define
  &#x9;app, nginx, queue, scheduler, mysql, and redis, with an optional
  &#x9;dedicated test profile.
- MySQL and Redis require health
  &#x9;checks; dependent services shall wait for healthy infrastructure
  &#x9;rather than relying only on startup order.
- Named volumes shall retain
  &#x9;MySQL and Redis development data. Application code may be
  &#x9;bind-mounted only in the development override.
- Environment variables shall be
  &#x9;supplied through .env and an example file without committed secrets.
  &#x9;Provider credentials must never be baked into an image.
- The container entrypoint shall
  &#x9;perform safe preparation such as directory permissions and cache
  &#x9;setup, but production migrations must run as an explicit deployment
  &#x9;step.
- Queue processes shall support
  &#x9;graceful termination so in-flight jobs return safely to the queue or
  &#x9;finish within the orchestration grace period.
- The README shall include build,
  &#x9;start, migrate, seed, queue/Horizon, scheduler, test, and teardown
  &#x9;commands.

# 15 Filament Screen

Implement one
read-only Instructor Financial Summary page or resource. It may use a
selected instructor record. Editing, refund initiation, retry
buttons, and manual status override are excluded.

|   |
| - |


&#x9;				**Area**

|   |
| - |


&#x9;				**Content**

|   |
| - |


&#x9;				Summary
&#x9;				cards

|   |
| - |


&#x9;				Gross
&#x9;				earned, adjustments, net earned, paid, reserved, outstanding,
&#x9;				each formatted from minor units and grouped by currency.

|   |
| - |


&#x9;				Payout
&#x9;				history

|   |
| - |


&#x9;				Created
&#x9;				date, amount, currency, status badge, provider reference,
&#x9;				idempotency key, submitted/completed dates.

|   |
| - |


&#x9;				Filters

|   |
| - |


&#x9;				Payout
&#x9;				status, currency, and date range.

|   |
| - |


&#x9;				Safety

|   |
| - |


&#x9;				Read-only
&#x9;				queries; no editable financial fields or bulk destructive
&#x9;				actions.

|   |
| - |


&#x9;				Performance

|   |
| - |


&#x9;				Paginated
&#x9;				payout history and aggregate/snapshot-backed cards; no per-row
&#x9;				N+1 queries.



# 16 Nonfunctional Requirements

|   |
| - |


&#x9;				**Category**

|   |
| - |


&#x9;				**Requirement**

|   |
| - |


&#x9;				Correctness

|   |
| - |


&#x9;				Financial
&#x9;				invariants are database-enforced where possible and covered by
&#x9;				tests.

|   |
| - |


&#x9;				Scale

|   |
| - |


&#x9;				500,000
&#x9;				active subscriptions and tens of millions of ledger/schedule
&#x9;				records; indexed, chunked, no full-table application scans.

|   |
| - |


&#x9;				Performance

|   |
| - |


&#x9;				Candidate
&#x9;				queries use covering/selective indexes; Filament uses
&#x9;				pagination; balance snapshots may serve reads.

|   |
| - |


&#x9;				Availability

|   |
| - |


&#x9;				Worker
&#x9;				crashes and provider outages delay processing without corrupting
&#x9;				balances or duplicating transfers.

|   |
| - |


&#x9;				Auditability

|   |
| - |


&#x9;				Every
&#x9;				financial amount links back to payment/refund and forward to
&#x9;				payout; attempts retain sanitized response data.

|   |
| - |


&#x9;				Security

|   |
| - |


&#x9;				Provider
&#x9;				credentials remain in secrets/config, logs redact destinations
&#x9;				and payload secrets, and admin UI is authorized.

|   |
| - |


&#x9;				Privacy

|   |
| - |


&#x9;				Store
&#x9;				a minimum payout destination snapshot or token; do not store
&#x9;				bank credentials in plaintext.

|   |
| - |


&#x9;				Observability

|   |
| - |


&#x9;				Structured
&#x9;				logs, metrics, and alerts use payout ID/idempotency key, not
&#x9;				sensitive destination data.

|   |
| - |


&#x9;				Time

|   |
| - |


&#x9;				All
&#x9;				persistence and financial cutoffs use UTC; UI may localize
&#x9;				display only.

|   |
| - |


&#x9;				Retention

|   |
| - |


&#x9;				Financial
&#x9;				records are not hard-deleted; retention period is configurable
&#x9;				to applicable policy.



# 17 Observability and Operations

- Metrics: payout
  &#x9;created/succeeded/permanently failed/unknown counts and amounts by
  &#x9;currency; provider latency; reconciliation age; queue lag; balance
  &#x9;snapshot lag.
- Alerts: UNKNOWN older than
  &#x9;threshold, stale PROCESSING payout, spike in permanent failures,
  &#x9;reconciliation not progressing, outbox backlog, and ledger
  &#x9;conservation failure.
- Structured log context:
  &#x9;payout_id, instructor_id, status transition, idempotency key hash,
  &#x9;attempt number, provider outcome, job UUID.
- Runbook: never manually create
  &#x9;a replacement for UNKNOWN; check provider by existing key, document
  &#x9;result, then transition through reconciliation tooling.
- Daily control report: total
  &#x9;recognized, adjusted, reserved, paid, and outstanding by currency,
  &#x9;with invariant exceptions highlighted.

# 18 Testing Strategy

## 18.1 Unit tests

- Money rejects mismatched
  &#x9;currency arithmetic and never uses floats.
- Platform share is correct for
  &#x9;boundary basis-point values.
- Equal and weighted splits
  &#x9;conserve the full amount.
- Uneven splits assign every
  &#x9;remainder deterministically.
- Daily schedules conserve the
  &#x9;instructor pool across monthly, 3-month, annual, and leap-year
  &#x9;terms.
- Refund allocation creates the
  &#x9;expected future cancellation and negative recognized adjustments.
- Every valid and invalid payout
  &#x9;state transition is tested.

## 18.2 Feature and integration tests

- Duplicate payment event
  &#x9;produces one payment, one schedule, and one set of ledger facts.
- Recognition job run twice
  &#x9;creates no duplicate earning entries.
- Payout command run twice
  &#x9;creates no duplicate reservation or logical payout.
- Two concurrent payout creators
  &#x9;cannot reserve the same ledger entry.
- The same
  &#x9;ProcessInstructorPayout job run repeatedly causes one provider
  &#x9;transfer.
- A worker retry after recorded
  &#x9;success exits without provider submission.
- Timeout-after-success creates
  &#x9;UNKNOWN, reconciliation finds success, provider transfer count
  &#x9;remains one, and outstanding becomes zero.
- Permanent failure releases
  &#x9;entries so a later newly-created payout can claim them with a new
  &#x9;logical key only after failure is confirmed.
- UNKNOWN never releases entries
  &#x9;and never creates a replacement payout.
- Mid-term full and partial
  &#x9;refunds preserve original rows and append adjustments.
- Filament page is authorized,
  &#x9;read-only, paginated, and displays correct aggregates.

## 18.3 Property and invariant tests

- For randomized valid inputs:
  &#x9;platform share plus all instructor shares equals payment amount.
- For every schedule: sum of
  &#x9;daily allocations equals the instructor pool.
- For every instructor/currency:
  &#x9;paid plus reserved never exceeds available net earned unless
  &#x9;explicitly allowed by a documented negative adjustment race policy.
- A ledger entry is linked to at
  &#x9;most one payout item.
- A logical payout produces at
  &#x9;most one provider-side transfer for its idempotency key.

# 19 Acceptance Criteria

|   |
| - |


&#x9;				**ID**

|   |
| - |


&#x9;				**Criterion**

|   |
| - |


&#x9;				AC01

|   |
| - |


&#x9;				Migrations
&#x9;				create all financial tables, foreign keys, unique constraints,
&#x9;				checks, and performance indexes.

|   |
| - |


&#x9;				AC02

|   |
| - |


&#x9;				Allocation
&#x9;				uses integer minor units and conserves money for equal and
&#x9;				weighted splits.

|   |
| - |


&#x9;				AC03

|   |
| - |


&#x9;				Payments
&#x9;				recognize revenue daily across the term with deterministic
&#x9;				remainders.

|   |
| - |


&#x9;				AC04

|   |
| - |


&#x9;				The
&#x9;				ledger is append-only and reports earned, adjusted, paid,
&#x9;				reserved, and outstanding balances.

|   |
| - |


&#x9;				AC05

|   |
| - |


&#x9;				Running
&#x9;				instructors\:payout twice produces no duplicate claim or provider
&#x9;				transfer.

|   |
| - |


&#x9;				AC06

|   |
| - |


&#x9;				Retried
&#x9;				payout jobs produce no duplicate provider transfer.

|   |
| - |


&#x9;				AC07

|   |
| - |


&#x9;				Timeout-after-success
&#x9;				is reconciled to success without resubmission.

|   |
| - |


&#x9;				AC08

|   |
| - |


&#x9;				Confirmed
&#x9;				permanent failure releases reservations; ambiguous outcomes do
&#x9;				not.

|   |
| - |


&#x9;				AC09

|   |
| - |


&#x9;				Refunds
&#x9;				stop future recognition and append negative adjustments when
&#x9;				recognized revenue is refunded.

|   |
| - |


&#x9;				AC10

|   |
| - |


&#x9;				Pest
&#x9;				suite contains unit, feature, retry, concurrency, and
&#x9;				provider-uncertainty coverage and passes.

|   |
| - |


&#x9;				AC11

|   |
| - |


&#x9;				One
&#x9;				read-only Filament screen shows balances and paginated payout
&#x9;				history.

|   |
| - |


&#x9;				AC12

|   |
| - |


&#x9;				README
&#x9;				documents assumptions, invariants, setup, commands, test
&#x9;				execution, scale decisions, and failure recovery.

|   |
| - |


&#x9;				AC13

|   |
| - |


&#x9;				Queries
&#x9;				process candidates in chunks and have indexes appropriate for
&#x9;				tens of millions of rows.

|   |
| - |


&#x9;				AC14

|   |
| - |


&#x9;				Sensitive
&#x9;				provider or destination information is not exposed in logs or
&#x9;				the UI.

|   |
| - |


&#x9;				AC15

|   |
| - |


&#x9;				The
&#x9;				Docker environment builds reproducibly and runs app, Nginx,
&#x9;				MySQL, Redis, queue/Horizon, scheduler, asset build, and Pest
&#x9;				test workflows with documented commands.

|   |
| - |


&#x9;				AC16

|   |
| - |


&#x9;				Redis
&#x9;				coordinates locks, queues, caches, idempotent responses, and
&#x9;				rate limits while tests prove that durable MySQL and provider
&#x9;				protections remain correct when Redis locks expire or jobs
&#x9;				overlap.



# 20 Delivery Structure


app/Domain/Money/

app/Domain/Revenue/

app/Domain/Ledger/

app/Domain/Payouts/

app/Application/Payments/

app/Application/Refunds/

app/Application/Payouts/

app/Infrastructure/Payments/

app/Jobs/

app/Console/Commands/

app/Filament/Resources/

database/migrations/

database/factories/

tests/Unit/

tests/Feature/

The folder structure
should make the business rules visible without forcing a complex
framework. Eloquent models remain persistence models; pure allocation
and state rules should not require a database to test.

## 20.1 Infrastructure files


Dockerfile

docker-compose.yml

docker-compose.override.yml.example

.dockerignore

docker/nginx/default.conf

docker/php/php.ini

docker/php/opcache.ini

docker/supervisor/horizon.conf

.env.example

The Dockerfile is
the canonical application runtime definition. Compose files
orchestrate local services and must not duplicate application build
logic across the app, queue, scheduler, and test processes.

# 21 Implementation Sequence


1\.  Create the multi-stage Dockerfile and Compose environment with
PHP 8.3 FPM, Composer, required PHP extensions, Nginx, MySQL 8, Redis
7, queue/Horizon, scheduler, Node 20 asset build, health checks,
volumes, and test runtime. Verify the empty Laravel application boots
and can connect to MySQL and Redis.


2\.  Create Money, enums, migrations, models, factories, and
invariant-focused database constraints.


3\.  Implement and unit-test RevenueAllocator and recognition schedule
logic.


4\.  Implement idempotent payment ingestion and revenue recognition
ledger writes.


5\.  Implement refunds and compensating entries.


6\.  Implement transactional payout reservation and the Artisan
discovery command.


7\.  Implement provider interface, controllable mock, payout state
machine, execution job, and reconciliation job.


8\.  Add concurrency/idempotency feature tests using MySQL for locking
behavior; SQLite is insufficient for final concurrency evidence.


9\.  Build the read-only Filament screen and aggregate queries.


10\.  Add Redis coordination, cached balance reads, durable
idempotency records, rate limits, Horizon configuration,
observability, runbook notes, seed/demo data, and final README
instructions.


11\.  Run the full Pest suite repeatedly and demonstrate
timeout-after-success recovery.

# 22 Risks and Mitigations

|   |
| - |


&#x9;				**Risk**

|   |
| - |


&#x9;				**Impact**

|   |
| - |


&#x9;				**Mitigation**

|   |
| - |


&#x9;				Provider
&#x9;				moved money but response was lost

|   |
| - |


&#x9;				Duplicate
&#x9;				payout if retried blindly

|   |
| - |


&#x9;				UNKNOWN
&#x9;				state, stable key, provider status reconciliation.

|   |
| - |


&#x9;				Concurrent
&#x9;				payout commands

|   |
| - |


&#x9;				Same
&#x9;				earnings reserved twice

|   |
| - |


&#x9;				Transactional
&#x9;				locking plus unique payout-item constraint.

|   |
| - |


&#x9;				Rules
&#x9;				change after payment

|   |
| - |


&#x9;				Historical
&#x9;				recalculation differs

|   |
| - |


&#x9;				Snapshot
&#x9;				basis points, term, weights, and currency at payment time.

|   |
| - |


&#x9;				Large
&#x9;				ledger aggregation

|   |
| - |


&#x9;				Slow
&#x9;				UI and payout discovery

|   |
| - |


&#x9;				Selective
&#x9;				indexes, incremental snapshots, chunks, and reconciliation
&#x9;				controls.

|   |
| - |


&#x9;				Refund
&#x9;				after instructor paid

|   |
| - |


&#x9;				Instructor
&#x9;				position becomes negative

|   |
| - |


&#x9;				Compensating
&#x9;				entry and future carry-forward; manual recovery policy outside
&#x9;				scope.

|   |
| - |


&#x9;				Job
&#x9;				dispatched before commit

|   |
| - |


&#x9;				Job
&#x9;				cannot find payout or sees partial state

|   |
| - |


&#x9;				afterCommit
&#x9;				dispatch or transactional outbox.

|   |
| - |


&#x9;				Random
&#x9;				mock makes tests flaky

|   |
| - |


&#x9;				Unreliable
&#x9;				CI evidence

|   |
| - |


&#x9;				Forceable
&#x9;				deterministic outcomes in tests; randomness only for demo.

|   |
| - |


&#x9;				Stale
&#x9;				worker overwrites success

|   |
| - |


&#x9;				Incorrect
&#x9;				terminal status

|   |
| - |


&#x9;				Conditional
&#x9;				state transitions and row-level coordination.

|   |
| - |


&#x9;				Redis
&#x9;				lock expires or Redis restarts

|   |
| - |


&#x9;				Concurrent
&#x9;				work may resume

|   |
| - |


&#x9;				Treat
&#x9;				Redis as coordination only; MySQL constraints/state and provider
&#x9;				idempotency remain authoritative.

|   |
| - |


&#x9;				Queue
&#x9;				and cache compete for Redis

|   |
| - |


&#x9;				Critical
&#x9;				payout jobs are delayed

|   |
| - |


&#x9;				Separate
&#x9;				connections/instances and dedicated critical queues with
&#x9;				resource limits.



# 23 Financial Invariants


1\.  Payment amount equals platform share plus instructor pool.


2\.  Instructor pool equals all scheduled instructor allocations.


3\.  No source key creates more than one ledger fact.


4\.  Ledger history is append-only; corrections are compensating
entries.


5\.  Each payable ledger entry is reserved by at most one payout item.


6\.  Each payout owns one stable provider idempotency key for its
lifetime.


7\.  A provider timeout is not a confirmed failure.


8\.  UNKNOWN payouts remain reserved until reconciled.


9\.  Succeeded payouts cannot be submitted again or transition
backward.


10\.  Balance snapshots and Redis values are caches and can be rebuilt
from authoritative records.


11\.  Redis lock ownership or availability never determines whether
money may move.

# 24 Definition of Done

The feature is done
when all acceptance criteria pass; migrations run on MySQL; the
application can seed a demonstration subscription, recognize
earnings, create and process a payout, simulate all provider
outcomes, and reconcile timeout-after-success; the read-only Filament
page displays correct balances; and the README lets a reviewer
reproduce the results. The submission must favor a small, auditable,
correct financial core over unrelated application breadth.