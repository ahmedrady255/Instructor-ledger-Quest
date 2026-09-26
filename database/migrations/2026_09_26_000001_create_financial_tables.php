<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->string('plan');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 32);
            $table->timestamps();
            $table->index(['status', 'ends_at'], 'subscriptions_status_ends_idx');
        });

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->string('provider_reference')->unique();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->dateTime('paid_at');
            $table->unsignedSmallInteger('platform_bps');
            $table->dateTime('term_start');
            $table->dateTime('term_end');
            $table->timestamps();
            $table->index(['subscription_id', 'paid_at'], 'payments_subscription_paid_idx');
        });

        Schema::create('payment_instructor_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('subscription_payments')->restrictOnDelete();
            $table->foreignId('instructor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('weight');
            $table->unsignedInteger('stable_order');
            $table->timestamps();
            $table->unique(['payment_id', 'instructor_id']);
        });

        Schema::create('revenue_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('subscription_payments')->restrictOnDelete();
            $table->date('service_date');
            $table->bigInteger('instructor_pool_minor');
            $table->string('status', 24)->default('PENDING');
            $table->dateTime('recognized_at')->nullable();
            $table->timestamps();
            $table->unique(['payment_id', 'service_date']);
            $table->index(['status', 'service_date'], 'schedule_status_date_idx');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('subscription_payments')->restrictOnDelete();
            $table->string('provider_reference')->unique();
            $table->bigInteger('amount_minor');
            $table->dateTime('effective_at');
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->index(['payment_id', 'effective_at'], 'refunds_payment_effective_idx');
        });

        Schema::create('instructor_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('subscription_payments')->restrictOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('schedule_item_id')->nullable()->constrained('revenue_schedule_items')->restrictOnDelete();
            $table->string('type', 32);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->dateTime('earned_at');
            $table->string('source_key')->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['instructor_id', 'currency', 'earned_at'], 'ledger_instructor_currency_earned_idx');
            $table->index(['type', 'earned_at'], 'ledger_type_earned_idx');
        });

        Schema::create('refund_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->constrained()->restrictOnDelete();
            $table->foreignId('schedule_item_id')->constrained('revenue_schedule_items')->restrictOnDelete();
            $table->foreignId('instructor_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 32);
            $table->bigInteger('amount_minor');
            $table->string('source_key')->unique();
            $table->timestamps();
            $table->index(['schedule_item_id', 'kind'], 'refund_allocations_schedule_kind_idx');
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('instructor_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key')->unique();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->json('destination_snapshot');
            $table->string('status', 32);
            $table->string('provider_reference')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->dateTime('manual_review_at')->nullable();
            $table->unsignedInteger('reconciliation_count')->default(0);
            $table->timestamps();
            $table->index(['status', 'created_at'], 'payouts_status_created_idx');
            $table->index(['instructor_id', 'currency', 'status'], 'payouts_instructor_currency_status_idx');
        });

        Schema::create('payout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('payout_id')->constrained()->restrictOnDelete();
            $table->foreignId('ledger_entry_id')->constrained('instructor_ledger_entries')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->dateTime('released_at')->nullable();
            $table->unsignedBigInteger('active_ledger_entry_id')
                ->storedAs('IF(released_at IS NULL, ledger_entry_id, NULL)');
            $table->timestamps();
            $table->unique('active_ledger_entry_id');
            $table->index('payout_id');
        });

        Schema::create('payout_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('payout_id')->constrained()->restrictOnDelete();
            $table->string('kind', 24);
            $table->unsignedInteger('attempt_no');
            $table->string('status', 32);
            $table->string('request_id')->nullable();
            $table->string('response_code')->nullable();
            $table->json('response_payload')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['payout_id', 'kind', 'attempt_no']);
            $table->index(['payout_id', 'started_at'], 'payout_attempts_payout_started_idx');
        });

        Schema::create('instructor_balance_snapshots', function (Blueprint $table) {
            $table->foreignId('instructor_id')->constrained('users')->restrictOnDelete();
            $table->char('currency', 3);
            $table->bigInteger('earned_minor')->default(0);
            $table->bigInteger('adjusted_minor')->default(0);
            $table->bigInteger('paid_minor')->default(0);
            $table->bigInteger('reserved_minor')->default(0);
            $table->bigInteger('outstanding_minor')->default(0);
            $table->dateTime('as_of');
            $table->timestamps();
            $table->primary(['instructor_id', 'currency']);
        });

        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->id();
            $table->string('scope');
            $table->string('key');
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamps();
            $table->unique(['scope', 'key']);
        });

        Schema::create('mock_provider_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->unique();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->json('destination_snapshot');
            $table->string('status', 32);
            $table->string('provider_reference')->nullable();
            $table->unsignedInteger('submission_count')->default(1);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT chk_subscriptions_term CHECK (starts_at < ends_at)');
        DB::statement('ALTER TABLE subscription_payments ADD CONSTRAINT chk_payments_amount CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE subscription_payments ADD CONSTRAINT chk_payments_bps CHECK (platform_bps BETWEEN 0 AND 10000)');
        DB::statement('ALTER TABLE subscription_payments ADD CONSTRAINT chk_payments_term CHECK (term_start < term_end)');
        DB::statement('ALTER TABLE payment_instructor_shares ADD CONSTRAINT chk_payment_shares_weight CHECK (weight > 0)');
        DB::statement('ALTER TABLE revenue_schedule_items ADD CONSTRAINT chk_schedule_pool CHECK (instructor_pool_minor >= 0)');
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT chk_refunds_amount CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE refund_allocations ADD CONSTRAINT chk_refund_allocations_amount CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE payouts ADD CONSTRAINT chk_payouts_amount CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE payout_items ADD CONSTRAINT chk_payout_items_amount CHECK (amount_minor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_provider_transfers');
        Schema::dropIfExists('idempotency_records');
        Schema::dropIfExists('instructor_balance_snapshots');
        Schema::dropIfExists('payout_attempts');
        Schema::dropIfExists('payout_items');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('refund_allocations');
        Schema::dropIfExists('instructor_ledger_entries');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('revenue_schedule_items');
        Schema::dropIfExists('payment_instructor_shares');
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscriptions');
    }
};
