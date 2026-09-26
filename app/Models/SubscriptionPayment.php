<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPayment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'platform_bps' => 'integer',
            'paid_at' => 'immutable_datetime',
            'term_start' => 'immutable_datetime',
            'term_end' => 'immutable_datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function instructorShares(): HasMany
    {
        return $this->hasMany(PaymentInstructorShare::class, 'payment_id');
    }

    public function scheduleItems(): HasMany
    {
        return $this->hasMany(RevenueScheduleItem::class, 'payment_id');
    }
}
