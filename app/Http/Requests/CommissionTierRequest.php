<?php

namespace App\Http\Requests;

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
        $isUpdate = $this->isMethod('put') || $this->isMethod('patch');

        return [
            'service_type_id' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'exists:service_types,id'],
            'amount_from' => [$isUpdate ? 'sometimes' : 'required', 'numeric', 'gt:0'],
            'amount_to' => [$isUpdate ? 'sometimes' : 'required', 'numeric', 'gt:0'],
            'fee_amount_type' => [$isUpdate ? 'sometimes' : 'required', Rule::in(['FIXED', 'PERCENTAGE'])],
            'fee_amount_deposit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'fee_amount_withdraw' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'fee_amount_cash_in' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'fee_amount_cash_out' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'comm_type' => ['sometimes', Rule::in(['FIXED', 'PERCENTAGE'])],
            'comm_deposit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'comm_withdraw' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'comm_cash_in' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'comm_cash_out' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'additional_fee_type' => ['sometimes', Rule::in(['FIXED', 'PERCENTAGE'])],
            'additional_fee_deposit_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'additional_fee_withdraw_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'additional_fee_cash_in_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'additional_fee_cash_out_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['fee_amount_type', 'comm_type', 'additional_fee_type'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([$field => strtoupper(trim($this->input($field)))]);
            }
        }
    }
}
