<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExchangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', Rule::in(['MMK', 'THB'])],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:255'],
            'exchange_payment_method' => ['sometimes', Rule::in(['cash', 'account'])],
            // Final pricing is always server-calculated from configured tiers.
            'customer_fee' => ['prohibited'],
            'additional_fee_amount' => ['prohibited'],
            'fee_payment_method' => ['sometimes', Rule::in(['cash', 'account'])],
            'fee_account_id' => ['nullable', 'integer', 'exists:accounts,id', 'required_if:fee_payment_method,account'],
            'screenshot_path' => ['sometimes', 'nullable', 'string', 'max:512'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'denominations' => ['sometimes', 'nullable', 'array'],
            'denominations.*' => ['integer', 'min:0'],
            'received_denominations' => ['sometimes', 'nullable', 'array'],
            'received_denominations.*' => ['integer', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $supported = Money::supportedDenominations();
            foreach (['denominations', 'received_denominations'] as $field) {
                $denoms = $this->input($field);
                if (! is_array($denoms) || $denoms === []) {
                    continue;
                }

                foreach ($denoms as $denom => $qty) {
                    if (! in_array((int) $denom, $supported, true)) {
                        $validator->errors()->add($field, "Unsupported denomination: {$denom}");
                    }
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency') && is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper(trim($this->input('currency')))]);
        }
    }
}
