<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashierVaultEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'cashier';
    }

    public function rules(): array
    {
        return [
            'entry_type' => ['required', Rule::in(['vault_in', 'adjustment'])],
            'denominations' => ['required', 'array', 'min:1'],
            'denominations.*' => ['integer', 'min:0'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach (array_keys($this->input('denominations', [])) as $denomination) {
                if (! in_array((int) $denomination, Money::supportedDenominations(), true)) {
                    $validator->errors()->add('denominations', "Unsupported denomination: {$denomination}");
                }
            }
        });
    }
}
