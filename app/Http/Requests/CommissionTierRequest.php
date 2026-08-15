<?php

namespace App\Http\Requests;

use App\Enums\AccountFeature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CommissionTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('put') || $this->isMethod('patch')
            ? 'sometimes'
            : 'required';

        return [
            'company_id' => [$presence, 'integer', 'exists:companies,id'],
            'feature' => [$presence, Rule::in(AccountFeature::values())],
            'amount_from' => [$presence, 'numeric', 'min:0'],
            'amount_to' => [$presence, 'numeric', 'gt:amount_from'],
            'fee_type' => [$presence, Rule::in(['FIXED', 'PERCENTAGE'])],
            'fee_amount' => [$presence, 'numeric', 'min:0', 'decimal:0,4'],
            'comm_type' => [$presence, Rule::in(['FIXED', 'PERCENTAGE'])],
            'comm_amount' => [$presence, 'numeric', 'min:0', 'decimal:0,4'],
            'additional_fee_type' => [$presence, Rule::in(['FIXED', 'PERCENTAGE'])],
            'additional_fee_amount' => [$presence, 'numeric', 'min:0', 'decimal:0,4'],
            'is_active' => [$presence, 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['fee_type', 'comm_type', 'additional_fee_type'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([$field => strtoupper(trim($this->input($field)))]);
            }
        }

        if ($this->has('feature') && is_string($this->input('feature'))) {
            $this->merge(['feature' => strtolower(trim($this->input('feature')))]);
        }
    }
}
