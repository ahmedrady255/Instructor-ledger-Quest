<?php

use App\Domain\Revenue\RefundAllocator;

it('conserves refunds with stable largest remainders', function () {
    $allocator = new RefundAllocator;

    expect($allocator->allocate(5, [10 => 2, 20 => 2, 30 => 2]))->toBe([10 => 2, 20 => 2, 30 => 1])
        ->and(array_sum($allocator->allocate(2, [10 => 100, 20 => 100, 30 => 100])))->toBe(2);
});

it('rejects refunds beyond remaining capacity', function () {
    expect(fn () => (new RefundAllocator)->allocate(4, [1 => 1, 2 => 2]))
        ->toThrow(InvalidArgumentException::class);
});

it('handles zero refund and zero-capacity buckets', function () {
    expect((new RefundAllocator)->allocate(0, [1 => 0, 2 => 10]))->toBe([1 => 0, 2 => 0]);
});
