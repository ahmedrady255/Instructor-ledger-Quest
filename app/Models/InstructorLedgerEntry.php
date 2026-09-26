<?php

namespace App\Models;

use App\Domain\Ledger\LedgerEntryType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstructorLedgerEntry extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount_minor' => 'integer',
            'earned_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class, 'payment_id');
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function scheduleItem(): BelongsTo
    {
        return $this->belongsTo(RevenueScheduleItem::class);
    }
}
