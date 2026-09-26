<?php

use App\Domain\Money\Money;

it('adds and subtracts signed minor units', function () {
    $balance = new Money(100, 'EGP');

    expect($balance->add(new Money(-30, 'EGP'))->minor)->toBe(70)
        ->and($balance->subtract(new Money(130, 'EGP'))->minor)->toBe(-30);
});

it('rejects arithmetic across currencies', function () {
    expect(fn () => (new Money(100, 'EGP'))->add(new Money(100, 'USD')))
        ->toThrow(InvalidArgumentException::class, 'Currency mismatch');
});

it('rejects invalid ISO currency codes', function (string $currency) {
    expect(fn () => new Money(100, $currency))->toThrow(InvalidArgumentException::class);
})->with(['egp', 'EG', 'EGPT', '123']);
