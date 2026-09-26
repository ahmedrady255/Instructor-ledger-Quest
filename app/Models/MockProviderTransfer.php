<?php

namespace App\Models;

use App\Domain\Payouts\ProviderOutcome;
use Illuminate\Database\Eloquent\Model;

class MockProviderTransfer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'destination_snapshot' => 'array',
            'status' => ProviderOutcome::class,
            'submission_count' => 'integer',
        ];
    }
}
