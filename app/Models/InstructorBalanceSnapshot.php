<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstructorBalanceSnapshot extends Model
{
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'earned_minor' => 'integer',
            'adjusted_minor' => 'integer',
            'paid_minor' => 'integer',
            'reserved_minor' => 'integer',
            'outstanding_minor' => 'integer',
            'as_of' => 'immutable_datetime',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }
}
