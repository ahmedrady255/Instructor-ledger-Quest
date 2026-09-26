<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'released_at' => 'immutable_datetime'];
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(InstructorLedgerEntry::class);
    }
}
