<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordSubscriptionPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider_reference' => ['required', 'string', 'max:255'],
            'subscription_id' => ['required', 'integer', 'exists:subscriptions,id'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'paid_at' => ['required', 'date_format:Y-m-d\\TH:i:s\\Z'],
            'term_start' => ['required', 'date_format:Y-m-d\\TH:i:s\\Z', 'regex:/^\\d{4}-\\d{2}-\\d{2}T00:00:00Z$/'],
            'term_end' => ['required', 'date_format:Y-m-d\\TH:i:s\\Z', 'regex:/^\\d{4}-\\d{2}-\\d{2}T00:00:00Z$/', 'after:term_start'],
            'platform_bps' => ['required', 'integer', 'between:0,10000'],
            'instructors' => ['required', 'array', 'min:1'],
            'instructors.*.instructor_id' => ['required', 'integer', 'distinct:strict', 'exists:users,id'],
            'instructors.*.weight' => ['required', 'integer', 'min:1'],
        ];
    }
}
