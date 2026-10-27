<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mobile_number' => ['required_without:email', 'prohibits:email', 'string'],
            'email' => ['required_without:mobile_number', 'prohibits:mobile_number', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }
}
