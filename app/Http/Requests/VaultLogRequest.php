<?php

namespace App\Http\Requests;

use App\Repositories\VaultTransactionRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VaultLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'txn_type' => ['nullable', 'string', Rule::in(VaultTransactionRepository::TYPES)],
            'float_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
