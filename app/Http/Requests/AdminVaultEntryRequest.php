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

            foreach (array_keys($denominations) as $denomination) {
                if (! in_array((int) $denomination, Money::supportedDenominations(), true)) {
                    $validator->errors()->add('denominations', "Unsupported denomination: {$denomination}");
                }
            }

            if ($validator->errors()->has('denominations')) {
                return;
            }

            $normalized = collect($denominations)
                ->mapWithKeys(fn ($quantity, $denomination): array => [(int) $denomination => (int) $quantity])
                ->all();

            if (Money::denominationTotal($normalized) <= 0) {
                $validator->errors()->add('denominations', 'Enter at least one banknote.');
            }
        });
    }
}
