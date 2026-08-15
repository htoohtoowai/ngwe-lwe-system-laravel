<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmFloatReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('return_denominations')) {
            if ($this->has('verified_denominations')) {
                $this->merge([
                    'return_denominations' => $this->input('verified_denominations'),
                ]);
            } elseif ($this->has('denominations')) {
                $this->merge([
                    'return_denominations' => $this->input('denominations'),
                ]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'closing_total' => ['required', 'numeric', 'min:0'],
            'pin' => ['required', 'string', 'regex:/^[0-9]{4,8}$/'],
            'return_denominations' => ['sometimes', 'array'],
            'return_denominations.*' => ['integer', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $denoms = $this->input('return_denominations', []);
            if (! is_array($denoms) || $denoms === []) {
                return;
            }

            $supported = Money::supportedDenominations();
            foreach ($denoms as $denom => $qty) {
                if (! in_array((int) $denom, $supported, true)) {
                    $validator->errors()->add('return_denominations', "Unsupported denomination: {$denom}");
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'pin.regex' => 'PIN must be 4–8 digits.',
        ];
    }
}
