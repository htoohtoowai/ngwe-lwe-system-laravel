<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashInRequest extends FormRequest
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
            // Final pricing is always server-calculated from configured tiers.
            'customer_fee' => ['prohibited'],
            'additional_fee_amount' => ['prohibited'],
            'fee_payment_method' => ['sometimes', Rule::in(['cash', 'account'])],
            'fee_account_id' => ['nullable', 'integer', 'exists:accounts,id', 'required_if:fee_payment_method,account'],
            'screenshot' => ['sometimes', 'nullable', 'file', 'image', 'max:4096'],
            'screenshot_path' => ['sometimes', 'nullable', 'string', 'max:512'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Teller only records the transaction. Physical cash is counted by the Cashier.
            'amount_received' => ['prohibited'],
            'received_denominations' => ['prohibited'],
            'handoff_denominations' => ['prohibited'],
            'change_denominations' => ['prohibited'],
        ];
    }

}
