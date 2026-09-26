<?php

use App\Domain\Revenue\RecognitionScheduleBuilder;
use Carbon\CarbonImmutable;

it('builds half-open UTC schedules that conserve the pool', function (string $start, string $end, int $expectedDays) {
    $days = (new RecognitionScheduleBuilder)->build(
        1000,
        CarbonImmutable::parse($start, 'UTC'),
        CarbonImmutable::parse($end, 'UTC'),
    );

    expect($days)->toHaveCount($expectedDays)
        ->and($days[0]->date->toDateString())->toBe(substr($start, 0, 10))
        ->and($days[$expectedDays - 1]->date->toDateString())
        ->toBe(CarbonImmutable::parse($end, 'UTC')->subDay()->toDateString())
        ->and(array_sum(array_map(fn ($day) => $day->instructorPoolMinor, $days)))->toBe(1000);
})->with([
    'one day' => ['2026-01-01', '2026-01-02', 1],
    'month' => ['2026-01-01', '2026-02-01', 31],
    'annual' => ['2025-01-01', '2026-01-01', 365],
    'leap year' => ['2024-01-01', '2025-01-01', 366],
]);

it('assigns daily remainder to the earliest service dates', function () {
    $days = (new RecognitionScheduleBuilder)->build(
        5,
        CarbonImmutable::parse('2026-01-01', 'UTC'),
        CarbonImmutable::parse('2026-01-04', 'UTC'),
    );

    expect(array_map(fn ($day) => $day->instructorPoolMinor, $days))->toBe([2, 2, 1]);
});

it('conserves a pool smaller than the service day count', function () {
    $days = (new RecognitionScheduleBuilder)->build(
        2,
        CarbonImmutable::parse('2026-01-01', 'UTC'),
        CarbonImmutable::parse('2026-01-05', 'UTC'),
    );

    expect(array_map(fn ($day) => $day->instructorPoolMinor, $days))->toBe([1, 1, 0, 0]);
});

it('rejects invalid schedule boundaries', function (int $pool, string $start, string $end) {
    expect(fn () => (new RecognitionScheduleBuilder)->build(
        $pool,
        CarbonImmutable::parse($start),
        CarbonImmutable::parse($end),
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'negative pool' => [-1, '2026-01-01 UTC', '2026-01-02 UTC'],
    'empty range' => [1, '2026-01-01 UTC', '2026-01-01 UTC'],
    'reversed range' => [1, '2026-01-02 UTC', '2026-01-01 UTC'],
    'non-midnight boundary' => [1, '2026-01-01 01:00 UTC', '2026-01-02 UTC'],
    'non-UTC boundary' => [1, '2026-01-01 Africa/Cairo', '2026-01-02 Africa/Cairo'],
]);
