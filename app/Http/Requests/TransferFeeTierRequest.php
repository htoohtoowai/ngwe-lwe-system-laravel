<?php

namespace App\Http\Requests;

use App\Enums\CalculationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferFeeTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    protected function prepareForValidation(): void
    {
        foreach (['fee_type', 'additional_fee_type'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtoupper(trim($this->input($field)))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'company_from_id' => ['required', 'integer', 'exists:companies,id'],
            'company_to_id' => ['required', 'integer', 'different:company_from_id', 'exists:companies,id'],
            'amount_from' => ['required', 'numeric', 'min:0'],
            'amount_to' => ['required', 'numeric', 'gt:amount_from'],
            'fee_type' => ['required', Rule::enum(CalculationType::class)],
            'fee_value' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
            'additional_fee_type' => ['required', Rule::enum(CalculationType::class)],
            'additional_fee_value' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
