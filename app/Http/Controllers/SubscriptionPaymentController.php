<?php

namespace App\Http\Controllers;

use App\Application\Payments\RecordSubscriptionPayment;
use App\Http\Requests\RecordSubscriptionPaymentRequest;
use DomainException;
use Illuminate\Http\JsonResponse;

class SubscriptionPaymentController extends Controller
{
    public function __invoke(
        RecordSubscriptionPaymentRequest $request,
        RecordSubscriptionPayment $recordPayment,
    ): JsonResponse {
        try {
            $payment = $recordPayment->handle($request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => [
            'id' => $payment->id,
            'provider_reference' => $payment->provider_reference,
            'subscription_id' => $payment->subscription_id,
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
        ]], $payment->wasRecentlyCreated ? 201 : 200);
    }
}
