<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_id' => ['required', 'integer', 'exists:subscription_payments,id'],
            'provider_reference' => ['required', 'string', 'max:255'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'effective_at' => ['required', 'date_format:Y-m-d\\TH:i:s\\Z'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
