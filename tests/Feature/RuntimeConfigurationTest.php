<?php

use Filament\Facades\Filament;

it('uses redis for production queue and cache', function () {
    expect(config('queue.connections.redis.driver'))->toBe('redis')
        ->and(config('cache.stores.redis.driver'))->toBe('redis');
});

it('keeps financial queues separate', function () {
    expect(config('horizon.defaults.supervisor-1.queue'))->toBe([
        'recognition',
        'payouts',
        'reconciliation',
    ]);
});

it('registers filament', function () {
    expect(Filament::getPanel('admin')->getPath())->toBe('admin');
});
