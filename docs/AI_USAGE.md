# AI Usage

## How I used AI

I used AI as a pair-programming and review tool throughout the task. It helped me turn the product requirements into an implementation plan, identify financial failure cases, draft Laravel code and Pest tests, inspect the resulting repository, and prepare documentation. I did not treat generated output as authoritative: financial behavior was checked against explicit invariants and executable tests.

The main workflow was:

1. Translate the requirements into invariants: conserve every minor unit, keep financial history append-only, make every externally triggered operation idempotent, and never retry an ambiguous transfer as a new transfer.
2. Split the design into pure domain logic, transactional application actions, durable database constraints, queued jobs, and read-only presentation.
3. Implement in small vertical slices with tests for the failure mode each slice was intended to prevent.
4. Re-read callers, database constraints, and tests after each slice to remove unnecessary abstractions and find gaps between the intended and actual behavior.

## Main prompts and workflows

I relied on prompts and review loops similar to these:

- Convert the PRD into a staged implementation plan with financial invariants and verification steps.
- Design the smallest schema that preserves an immutable audit trail and prevents duplicate active reservations.
- Implement deterministic integer allocation using stable largest remainders, including amounts smaller than the number of recipients or service days.
- Trace payment, recognition, refund, balance, payout, provider timeout, and reconciliation flows end to end; identify where retries or races could move money twice.
- Write focused tests for duplicate API requests, duplicate jobs, overlapping payout runs, timeout-after-success, post-payout refunds, and rounding conservation.
- Review the final repository and draft an evidence-based architecture and failure-scenario walkthrough.

I did not need to preserve every conversational prompt because the durable artifacts are the implementation plan, commit history, code, and automated tests.

## Generated versus manually owned work

AI produced first-pass suggestions and drafts for parts of the implementation, tests, implementation plan, and documentation. I treated those as proposals rather than decisions.

I reviewed and own the final behavior, including:

- the domain boundaries and database schema;
- the money, recognition, refund, balance, and payout invariants;
- the choice of database constraints and conditional writes as the correctness boundary;
- the decision to retain payout reservations while the provider outcome is unknown;
- the test scenarios and the interpretation of their results; and
- the trade-offs and production improvements described below.

No AI-generated result was accepted solely because it looked plausible. The financial paths are backed by database constraints, deterministic arithmetic, and tests that exercise retries and ambiguous outcomes.

## Engineering decisions I made

### Integer money and deterministic allocation

All amounts are signed `BIGINT` minor units with an ISO currency. Allocation uses integer division and stable largest-remainder rules; it never uses floating point. Instructor ID breaks equal remainders, and earlier service dates receive daily schedule remainders. The result is reproducible and conserves the original amount exactly.

### Append-only financial facts

Payments, recognized earnings, refund adjustments, payouts, and payout attempts remain auditable. Refunds add future-cancellation allocations or negative ledger entries instead of rewriting previous earnings. A refund after a successful payout creates a carried deficit rather than pretending the historical payout did not occur.

### MySQL is authoritative

Redis improves discovery locking, response caching, and queue operation, but correctness does not depend on it. Unique keys, foreign-key restrictions, transactions, row locks, generated columns, and conditional status updates enforce the money invariants in MySQL.

### Reservations before external transfer

A payout reserves specific immutable earning entries before a provider call. A database uniqueness rule allows only one active reservation for a ledger entry. Overlapping payout runs therefore converge safely even if an optional Redis discovery lock is unavailable.

### Unknown is a real payout state

A timeout can mean the provider failed, succeeded, or is still processing. The system records `UNKNOWN`, retains the reservation, and performs status lookup with the original provider idempotency key. It does not submit a second transfer. Reservations are released only after a confirmed permanent failure.

### Derived balances with rebuildable snapshots

The append-only ledger and payout records are the source of truth. Balances are derived as earned, adjusted, paid, reserved, outstanding, and deficit amounts at a point in time. MySQL and Redis snapshots are rebuildable read optimizations, not independent financial truth.

## What differentiates this solution

The solution is designed around failure semantics rather than only the happy path. Idempotency exists at several independent boundaries: HTTP request keys, provider references, deterministic ledger source keys, one active payout reservation per earning, conditional payout claims, and provider-side idempotency keys. Each mechanism covers a different retry or race; none relies on a single distributed lock.

The implementation also distinguishes a confirmed failure from an ambiguous outcome. That distinction is the key protection against timeout-after-success duplicate transfers and is exercised by the mock provider and feature tests.

## Trade-offs and intentional improvements

- Revenue is recognized daily in UTC over a half-open term. This is deterministic and auditable, but another business may require event-based or jurisdiction-specific recognition.
- Payouts reserve whole earning entries and cap each payout at a configured number of entries. This keeps claims simple and traceable, but can leave a small payable remainder until a later run.
- Balance calculation queries authoritative facts and then refreshes snapshots. At much larger scale, I would introduce partitioning or incremental projections with periodic reconciliation, while keeping the ledger authoritative.
- Jobs dispatch after database commit. A production system with a strict no-loss dispatch requirement should add a transactional outbox and an outbox relay.
- The payment provider is behind an interface but represented by an idempotent unreliable mock. Production work would add signed webhooks, credential management, provider-specific error mapping, rate limiting, and operational tooling for manual review.
- The current design keeps each payout in a single currency and performs no conversion. FX, fees, taxes, KYC, chargebacks, and clawbacks are intentionally outside scope.

