<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'regex:/^[0-9]{4,8}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'pin.regex' => 'PIN must be 4–8 digits.',
        ];
    }
}
