<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'to_account_id' => ['required', 'integer', 'exists:accounts,id', 'different:from_account_id'],
            'source_account_type' => ['nullable', Rule::in(['account', 'pay', 'bank'])],
            'source_provider' => ['nullable', 'string', 'max:255', 'required_with:source_account_type'],
            'source_account_number' => ['nullable', 'string', 'max:255', 'required_with:source_account_type'],
            'destination_provider' => ['nullable', 'string', 'max:255', 'required_with:source_account_type'],
            'destination_customer_name' => ['nullable', 'string', 'max:255', 'required_with:source_account_type'],
            'destination_account_number' => ['nullable', 'string', 'max:255', 'required_with:source_account_type'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:255'],
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
            if (
                $this->input('fee_payment_method') === 'account'
                && ! $this->filled('source_account_type')
                && ! $this->filled('fee_account_id')
            ) {
                $validator->errors()->add('fee_account_id', 'A fee account is required when the fee is paid from an account.');
            }

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
