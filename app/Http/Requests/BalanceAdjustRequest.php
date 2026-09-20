<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BalanceAdjustRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric'],
            'remark' => ['sometimes', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('remark') && is_string($this->input('remark'))) {
            $this->merge(['remark' => trim($this->input('remark'))]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $validator->errors()->add(
                'form',
                'Direct Admin balance adjustment is disabled. Use Admin Adjustment Requests so the branch Cashier can confirm with PIN.',
            );
        });
    }
}
