<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashOutRequest extends FormRequest
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
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:255'],
            'customer_fee' => ['sometimes', 'numeric', 'min:0'],
            'additional_fee_amount' => ['sometimes', 'numeric', 'min:0'],
            'fee_payment_method' => ['sometimes', Rule::in(['cash', 'account'])],
            'fee_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'screenshot_path' => ['sometimes', 'nullable', 'string', 'max:512'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'denominations' => ['sometimes', 'nullable', 'array'],
            'denominations.*' => ['integer', 'min:0'],
            'fee_denominations' => ['sometimes', 'nullable', 'array'],
            'fee_denominations.*' => ['integer', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $supported = Money::supportedDenominations();

            foreach (['denominations', 'fee_denominations'] as $field) {
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
}
