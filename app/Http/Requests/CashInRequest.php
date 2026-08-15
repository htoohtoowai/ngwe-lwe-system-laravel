<?php

namespace App\Http\Requests;

use App\Support\Money;
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
            'amount_received' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'received_denominations' => ['sometimes', 'nullable', 'array'],
            'received_denominations.*' => ['integer', 'min:0'],
            'handoff_denominations' => ['sometimes', 'nullable', 'array'],
            'handoff_denominations.*' => ['integer', 'min:0'],
            'change_denominations' => ['sometimes', 'nullable', 'array'],
            'change_denominations.*' => ['integer', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $aliases = [];

        if ($this->has('received_breakdown') && ! $this->has('received_denominations')) {
            $aliases['received_denominations'] = $this->input('received_breakdown');
        }

        if ($this->has('change_breakdown') && ! $this->has('change_denominations')) {
            $aliases['change_denominations'] = $this->input('change_breakdown');
        }

        if ($aliases !== []) {
            $this->merge($aliases);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $denoms = $this->input('handoff_denominations');
            if (! is_array($denoms) || $denoms === []) {
                return;
            }

            $supported = Money::supportedDenominations();
            foreach ($denoms as $denom => $qty) {
                if (! in_array((int) $denom, $supported, true)) {
                    $validator->errors()->add('handoff_denominations', "Unsupported denomination: {$denom}");
                }
            }
        });

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

        $validator->after(function ($validator): void {
            $denoms = $this->input('received_denominations');
            if (! is_array($denoms) || $denoms === []) {
                return;
            }

            $supported = Money::supportedDenominations();
            foreach ($denoms as $denom => $qty) {
                if (! in_array((int) $denom, $supported, true)) {
                    $validator->errors()->add('received_denominations', "Unsupported denomination: {$denom}");
                }
            }
        });
    }
}
