<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('patch') || $this->isMethod('put');
        $nestedCompany = $this->route('company') !== null;

        return [
            'company_id' => [$isUpdate || $nestedCompany ? 'sometimes' : 'required', 'integer', 'exists:companies,id'],
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'operation' => [$isUpdate ? 'sometimes' : 'required', Rule::in(['CashIn', 'CashOut', 'Transfer', 'Exchange', 'All'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
