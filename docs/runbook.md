# Instructor Ledger Runbook

## Routine operation

Run Horizon and the scheduler continuously. The scheduler queues daily recognition and payout discovery, reconciliation every five minutes, and the daily control report.

```bash
docker compose up -d mysql redis app nginx queue scheduler
docker compose exec app php artisan horizon:status
docker compose exec app php artisan schedule:list
docker compose exec app php artisan instructors:ledger-control --date=2026-09-27
```

Financial logs are JSON on the `financial` channel. They contain payout IDs, outcomes, and SHA-256 idempotency-key hashes; they never contain destinations, credentials, or provider payloads.

## Provider scenarios

`MOCK_PROVIDER_OUTCOME` accepts `success`, `permanent_failure`, or `timeout_after_success`. Change it only for local demonstrations, then restart workers so they reload configuration.

```bash
docker compose exec app php artisan horizon:terminate
```

## Failure recovery

- `PENDING`: safe for the submission worker to claim once.
- stale `PROCESSING`: run `php artisan instructors:reconcile-payouts --older-than=5m`; do not submit again.
- `UNKNOWN`: retain its reservation and reconcile with the same persisted idempotency key. **Never create a replacement payout manually.**
- `PERMANENTLY_FAILED`: reservations are released only after provider confirmation; payout discovery may claim them again under a new payout.
- `SUCCEEDED`: terminal. A repeated worker exits without contacting the provider.

If Redis is unavailable, MySQL constraints remain authoritative. Restore Redis and restart Horizon; repeated commands/jobs are safe. Do not edit ledger entries, schedules, payout items, provider references, or idempotency records. Rebuild a balance snapshot by running the relevant financial workflow or from Tinker with `RefreshInstructorBalanceSnapshot::handle(instructorId, currency)`.

## Checks and escalation

```bash
docker compose exec app php artisan instructors:reconcile-payouts --older-than=5m --limit=1000
docker compose exec app php artisan instructors:payout --currency=EGP --limit=1000 --dry-run
docker compose exec app php artisan queue:failed
docker compose logs --tail=200 queue scheduler
```

Escalate when an `UNKNOWN` payout reaches `manual_review_at`, reconciliation repeatedly returns not found, control totals change unexpectedly, or queue failures persist. Preserve all rows and provider evidence.
