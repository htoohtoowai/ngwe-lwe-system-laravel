<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('patch') || $this->isMethod('put');
        $userId = $this->route('user')?->id;

        return [
            'username' => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                'alpha_dash',
                'max:100',
                Rule::unique('users', 'username')->ignore($userId),
            ],
            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'full_name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'role' => [$isUpdate ? 'sometimes' : 'required', Rule::in(['owner', 'cashier', 'employee'])],
            'password' => [$isUpdate ? 'sometimes' : 'required', 'string', 'min:8', 'max:255'],
            'pin' => ['sometimes', 'nullable', 'digits_between:4,8'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['username', 'email', 'full_name'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }

        if ($this->has('username') && is_string($this->input('username'))) {
            $this->merge(['username' => strtolower($this->input('username'))]);
        }
    }
}
