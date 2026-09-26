<?php

namespace App\Models;

use App\Domain\Payouts\PayoutAttemptKind;
use App\Domain\Payouts\ProviderOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutAttempt extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => PayoutAttemptKind::class,
            'status' => ProviderOutcome::class,
            'attempt_no' => 'integer',
            'response_payload' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }
}
