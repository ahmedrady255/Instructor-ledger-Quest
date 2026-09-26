<?php

namespace App\Http\Controllers;

use App\Application\Refunds\RecordRefund;
use App\Http\Requests\RecordRefundRequest;
use DomainException;
use Illuminate\Http\JsonResponse;

class RefundController extends Controller
{
    public function __invoke(RecordRefundRequest $request, RecordRefund $recordRefund): JsonResponse
    {
        try {
            $refund = $recordRefund->handle($request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => [
            'id' => $refund->id,
            'payment_id' => $refund->payment_id,
            'provider_reference' => $refund->provider_reference,
            'amount_minor' => $refund->amount_minor,
        ]], $refund->wasRecentlyCreated ? 201 : 200);
    }
}
