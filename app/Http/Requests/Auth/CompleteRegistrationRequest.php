<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CompleteRegistrationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'registration_token' => ['required', 'string', 'max:200'],
            'password' => ['required', 'confirmed', 'string', 'min:8', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).+$/'],
            'referral_code' => ['nullable', 'string', 'max:32'],
            'signup_from' => ['sometimes', 'nullable', 'string', 'in:web,android,ios'],
        ];
    }

    public function messages(): array
    {
        return ['password.regex' => 'The password needs uppercase, lowercase, a number, and a symbol.'];
    }
}
