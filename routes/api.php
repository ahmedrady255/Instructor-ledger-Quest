<?php

use App\Http\Controllers\SubscriptionPaymentController;
use App\Http\Middleware\FinancialIdempotency;
use Illuminate\Support\Facades\Route;

Route::post('/subscription-payments', SubscriptionPaymentController::class)
    ->middleware(['throttle:financial', FinancialIdempotency::class])
    ->name('subscription-payments.store');
