<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mobile_number' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'delivery_method' => ['sometimes', 'in:sms'],
        ];
    }

    public function messages(): array
    {
        return [
            'mobile_number.regex' => 'A valid mobile number in E.164 format is required.',
        ];
    }
}
