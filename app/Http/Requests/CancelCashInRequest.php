<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelCashInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pin' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{4,8}$/'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'pin.regex' => 'PIN must be 4–8 digits.',
        ];
    }
}
