<?php

use App\Domain\Money\Money;
use App\Domain\Revenue\InstructorWeight;
use App\Domain\Revenue\RevenueAllocator;

it('allocates platform boundaries and conserves every minor unit', function (int $basisPoints, int $platform, array $shares) {
    $result = (new RevenueAllocator)->allocate(
        new Money(101, 'EGP'),
        $basisPoints,
        [new InstructorWeight(10, 1), new InstructorWeight(20, 1)],
    );

    expect($result->platformShare->minor)->toBe($platform)
        ->and(array_map(fn (Money $money) => $money->minor, $result->instructorAllocations))->toBe($shares)
        ->and($result->platformShare->minor + array_sum($shares))->toBe(101);
})->with([
    'zero platform share' => [0, 0, [10 => 51, 20 => 50]],
    'full platform share' => [10_000, 101, [10 => 0, 20 => 0]],
]);

it('uses largest remainders then instructor id as the stable tie break', function () {
    $result = (new RevenueAllocator)->allocate(
        new Money(10, 'EGP'),
        0,
        [new InstructorWeight(30, 1), new InstructorWeight(10, 1), new InstructorWeight(20, 1)],
    );

    expect(array_map(fn (Money $money) => $money->minor, $result->instructorAllocations))
        ->toBe([10 => 4, 20 => 3, 30 => 3]);
});

it('allocates weighted remainders deterministically', function () {
    $result = (new RevenueAllocator)->allocate(
        new Money(17, 'USD'),
        2000,
        [new InstructorWeight(1, 5), new InstructorWeight(2, 3), new InstructorWeight(3, 2)],
    );

    expect($result->platformShare->minor)->toBe(3)
        ->and($result->instructorPool->minor)->toBe(14)
        ->and(array_map(fn (Money $money) => $money->minor, $result->instructorAllocations))
        ->toBe([1 => 7, 2 => 4, 3 => 3]);
});

it('conserves amounts smaller than the instructor count', function () {
    $result = (new RevenueAllocator)->allocate(
        new Money(2, 'EGP'),
        0,
        [new InstructorWeight(3, 1), new InstructorWeight(1, 1), new InstructorWeight(2, 1)],
    );

    expect(array_map(fn (Money $money) => $money->minor, $result->instructorAllocations))
        ->toBe([1 => 1, 2 => 1, 3 => 0]);
});

it('keeps weighted allocation exact near the signed BIGINT limit', function () {
    $result = (new RevenueAllocator)->allocate(
        new Money(8_000_000_000_000_000_000, 'EGP'),
        0,
        [
            new InstructorWeight(1, 2_000_000_000_000_000_000),
            new InstructorWeight(2, 1_000_000_000_000_000_000),
        ],
    );

    expect(array_map(fn (Money $money) => $money->minor, $result->instructorAllocations))->toBe([
        1 => 5_333_333_333_333_333_333,
        2 => 2_666_666_666_666_666_667,
    ]);
});

it('rejects invalid allocation inputs', function (int $minor, int $basisPoints, array $weightValues) {
    $weights = array_map(fn (array $weight) => new InstructorWeight(...$weight), $weightValues);

    expect(fn () => (new RevenueAllocator)->allocate(new Money($minor, 'EGP'), $basisPoints, $weights))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'negative payment' => [-1, 0, [[1, 1]]],
    'negative basis points' => [1, -1, [[1, 1]]],
    'basis points too high' => [1, 10_001, [[1, 1]]],
    'no instructors' => [1, 0, []],
    'duplicate instructors' => [1, 0, [[1, 1], [1, 2]]],
]);

it('rejects non-positive instructor weights', function (int $weight) {
    expect(fn () => new InstructorWeight(1, $weight))->toThrow(InvalidArgumentException::class);
})->with([0, -1]);
