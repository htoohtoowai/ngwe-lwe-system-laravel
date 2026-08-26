<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendMoneyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'teller';
    }

    public function rules(): array
    {
        return [
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:255'],
            'destination_customer_name' => ['required', 'string', 'max:255'],
            'destination_account_number' => ['required', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'customer_fee' => ['prohibited'],
            'customer_total' => ['prohibited'],
            'additional_fee_amount' => ['prohibited'],
            'fee_payment_method' => ['prohibited'],
            'fee_account_id' => ['prohibited'],
            'received_denominations' => ['prohibited'],
        ];
    }
}
