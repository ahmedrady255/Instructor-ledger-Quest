<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyRecord extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['response_code' => 'integer', 'response_body' => 'array'];
    }
}
