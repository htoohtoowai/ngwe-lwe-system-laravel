<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class CashInRequest extends FormRequest
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
            'fee_account_id' => ['sometimes', 'nullable', 'integer', 'exists:accounts,id'],
            'screenshot_path' => ['sometimes', 'nullable', 'string', 'max:512'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'amount_received' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'change_denominations' => ['sometimes', 'nullable', 'array'],
            'change_denominations.*' => ['integer', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $denoms = $this->input('change_denominations');
            if (! is_array($denoms) || $denoms === []) {
                return;
            }

            $supported = Money::supportedDenominations();
            foreach ($denoms as $denom => $qty) {
                if (! in_array((int) $denom, $supported, true)) {
                    $validator->errors()->add('change_denominations', "Unsupported denomination: {$denom}");
                }
            }
        });
    }
}
