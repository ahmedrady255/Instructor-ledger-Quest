<?php

namespace App\Models;

use App\Domain\Payouts\PayoutStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payout extends Model
{
    use HasFactory, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'destination_snapshot' => 'array',
            'status' => PayoutStatus::class,
            'submitted_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'manual_review_at' => 'immutable_datetime',
            'reconciliation_count' => 'integer',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayoutItem::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PayoutAttempt::class);
    }
}
