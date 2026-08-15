<?php

namespace App\Http\Requests;

use App\Enums\AccountFeature;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('patch') || $this->isMethod('put');

        return [
            'company_id' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'exists:companies,id'],
            'account_name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'account_type' => [$isUpdate ? 'sometimes' : 'required', Rule::enum(AccountType::class)],
            'account_identifier' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'balance' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'is_fee_account' => ['sometimes', 'boolean'],
            'is_agent' => ['sometimes', 'boolean'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['string', Rule::in(AccountFeature::values())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $account = $this->route('account');
            $duplicate = Account::query()
                ->where('company_id', $this->input('company_id', $account?->company_id))
                ->where('account_identifier', $this->input('account_identifier', $account?->account_identifier))
                ->when($account, fn ($query) => $query->where('id', '!=', $account->id))
                ->exists();

            if ($duplicate) {
                $validator->errors()->add(
                    'account_identifier',
                    'This provider already has an account with the same identifier.'
                );
            }

            $currentAccountType = $account?->account_type instanceof AccountType
                ? $account->account_type->value
                : ($account?->account_type ? (string) $account->account_type : null);
            $accountType = (string) $this->input('account_type', $currentAccountType);
            $isAgent = $this->has('is_agent') ? $this->boolean('is_agent') : (bool) ($account?->is_agent ?? false);

            if ($accountType === AccountType::Bank->value && $isAgent) {
                $validator->errors()->add('is_agent', 'Bank accounts cannot be agent accounts.');
            }

            $companyId = (int) $this->input('company_id', $account?->company_id);
            $company = Company::query()->find($companyId);
            if ($company !== null) {
                $requiredAccountType = match ($company->category) {
                    'Pay' => AccountType::Pay->value,
                    'Bank' => AccountType::Bank->value,
                    default => null,
                };

                if ($requiredAccountType !== null && $accountType !== $requiredAccountType) {
                    $validator->errors()->add(
                        'account_type',
                        "{$company->category} providers require {$requiredAccountType} accounts."
                    );
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('account_type') && is_string($this->input('account_type'))) {
            $this->merge(['account_type' => strtoupper(trim($this->input('account_type')))]);
        }

        foreach (['account_name', 'account_identifier'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }
}
