<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('patch') || $this->isMethod('put');

        return [
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'base_currency' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:8'],
            'quote_currency' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:8'],
            'base_amount' => [$isUpdate ? 'sometimes' : 'required', 'numeric', 'gt:0'],
            'buy_rate' => [$isUpdate ? 'sometimes' : 'required', 'numeric', 'gt:0'],
            'sell_rate' => [$isUpdate ? 'sometimes' : 'required', 'numeric', 'gt:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['base_currency', 'quote_currency'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([$field => strtoupper(trim($this->input($field)))]);
            }
        }
    }
}
