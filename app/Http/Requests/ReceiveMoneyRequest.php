<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class ReceiveMoneyRequest extends FormRequest
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
            'source_account_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'customer_fee' => ['prohibited'],
            'customer_total' => ['prohibited'],
            'additional_fee_amount' => ['prohibited'],
            'fee_payment_method' => ['prohibited'],
            'fee_account_id' => ['prohibited'],
            'denominations' => ['required', 'array'],
            'denominations.*' => ['integer', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach (['denominations'] as $field) {
                $denoms = $this->input($field);
                if (! is_array($denoms) || $denoms === []) {
                    continue;
                }

                foreach ($denoms as $denom => $qty) {
                    if (! in_array((int) $denom, Money::supportedDenominations(), true)) {
                        $validator->errors()->add($field, "Unsupported denomination: {$denom}");
                    }
                }
            }
        });
    }
}
