<?php

namespace App\Http\Requests;

use App\Enums\CalculationType;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AgentCommissionTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('commission_type'))) {
            $this->merge(['commission_type' => strtoupper(trim($this->input('commission_type')))]);
        }
    }


    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $company = Company::query()->find((int) $this->input('company_id'));
            if ($company?->category === 'Bank') {
                $validator->errors()->add(
                    'company_id',
                    'Bank providers do not support agent commission tiers. Choose a Pay provider.'
                );
            }
        });
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'amount_from' => ['required', 'numeric', 'min:0'],
            'amount_to' => ['required', 'numeric', 'gt:amount_from'],
            'commission_type' => ['required', Rule::enum(CalculationType::class)],
            'out_commission_value' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
            'in_commission_value' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
