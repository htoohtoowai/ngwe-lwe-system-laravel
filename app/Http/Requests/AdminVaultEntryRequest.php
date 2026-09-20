<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminVaultEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'entry_type' => ['required', Rule::in(['vault_in', 'vault_out'])],
            'denominations' => ['required', 'array', 'min:1'],
            'denominations.*' => ['integer', 'min:0'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $denominations = $this->input('denominations', []);

            foreach (array_keys(is_array($denominations) ? $denominations : []) as $denomination) {
                if (! in_array((int) $denomination, Money::supportedDenominations(), true)) {
                    $validator->errors()->add(
                        'denominations',
                        "Unsupported denomination: {$denomination}",
                    );
                }
            }

            $validator->errors()->add(
                'form',
                'Direct Admin vault mutation is disabled. Submit a Cash adjustment request for Cashier PIN confirmation.',
            );
        });
    }
}
