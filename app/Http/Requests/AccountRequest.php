<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
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
            'service_type_id' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'exists:service_types,id'],
            'account_name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'phone_number' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'balance' => ['sometimes', 'numeric', 'min:0'],
            'commission_rate' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'is_fee_account' => ['sometimes', 'boolean'],
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
                ->where('service_type_id', $this->input('service_type_id', $account?->service_type_id))
                ->where('account_name', $this->input('account_name', $account?->account_name))
                ->where('phone_number', $this->input('phone_number', $account?->phone_number))
                ->when($account, fn ($query) => $query->where('id', '!=', $account->id))
                ->exists();

            if ($duplicate) {
                $validator->errors()->add(
                    'phone_number',
                    'This service type, account name, and account number already exist.'
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        foreach (['account_name', 'phone_number'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }
}
