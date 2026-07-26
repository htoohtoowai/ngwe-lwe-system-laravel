<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueCashFloatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'teller')->where('is_active', true)],
            'denominations' => ['required', 'array', 'min:1'],
            'denominations.*' => ['integer', 'min:0'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $denoms = $this->input('denominations', []);
            if (! is_array($denoms) || $denoms === []) {
                return;
            }

            $supported = Money::supportedDenominations();
            foreach ($denoms as $denom => $qty) {
                if (! in_array((int) $denom, $supported, true)) {
                    $validator->errors()->add('denominations', "Unsupported denomination: {$denom}");
                }
            }
        });
    }
}
