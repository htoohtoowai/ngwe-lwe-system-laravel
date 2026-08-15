<?php

namespace App\Http\Requests;

use App\Enums\AccountFeature;
use App\Enums\CalculationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProviderFeeTierRequest extends FormRequest
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

        if (is_string($this->input('feature'))) {
            $this->merge(['feature' => strtolower(trim($this->input('feature')))]);
        }
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'feature' => ['required', Rule::in([
                AccountFeature::CashIn->value,
                AccountFeature::CashOut->value,
                AccountFeature::SendMoney->value,
                AccountFeature::ReceiveMoney->value,
            ])],
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
