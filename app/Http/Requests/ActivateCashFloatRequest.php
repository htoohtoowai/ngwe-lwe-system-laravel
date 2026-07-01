<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class ActivateCashFloatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'regex:/^[0-9]{4,8}$/'],
            'verified_denominations' => ['required', 'array', 'min:1'],
            'verified_denominations.*' => ['integer', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $denoms = $this->input('verified_denominations', []);
            if (! is_array($denoms) || $denoms === []) {
                return;
            }

            $supported = Money::supportedDenominations();
            foreach ($denoms as $denom => $qty) {
                if (! in_array((int) $denom, $supported, true)) {
                    $validator->errors()->add('verified_denominations', "Unsupported denomination: {$denom}");
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
