<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmPendingCashInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'cashier';
    }

    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'regex:/^[0-9]{4,8}$/'],
            'received_denominations' => ['required', 'array'],
            'received_denominations.*' => ['integer', 'min:0'],
            'change_denominations' => ['sometimes', 'nullable', 'array'],
            'change_denominations.*' => ['integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'pin.regex' => 'PIN must be 4-8 digits.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach (['received_denominations', 'change_denominations'] as $field) {
                $denominations = $this->input($field);
                if (! is_array($denominations) || $denominations === []) {
                    continue;
                }

                foreach ($denominations as $denomination => $quantity) {
                    if (! in_array((int) $denomination, Money::supportedDenominations(), true)) {
                        $validator->errors()->add($field, "Unsupported denomination: {$denomination}");
                    }
                }
            }
        });
    }
}
