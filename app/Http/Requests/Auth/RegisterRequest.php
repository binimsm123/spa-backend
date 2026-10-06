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
            'mobile_number' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/', 'unique:users,mobile'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'password' => ['required','confirmed', 'string', 'min:8', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).+$/'],
            'display_name' => ['sometimes', 'string', 'max:120'],
            'delivery_method' => ['sometimes', 'in:sms'],
            'timezone' => ['nullable', 'timezone:all'],
            'referral_code' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function messages(): array
    {
        return [
            'mobile_number.regex' => 'A valid mobile number in E.164 format is required.',
            'password.regex' => 'The password needs uppercase, lowercase, a number, and a symbol.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('referral_code')) {
            $this->merge(['referral_code' => strtoupper(trim((string) $this->input('referral_code')))]);
        }
    }
}
