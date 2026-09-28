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

it('publishes filament assets after composer installs dependencies', function () {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['post-autoload-dump'])
        ->toContain('@php artisan filament:upgrade')
        ->and(public_path('css/filament/filament/app.css'))->toBeFile()
        ->and(public_path('js/filament/filament/app.js'))->toBeFile();
});
