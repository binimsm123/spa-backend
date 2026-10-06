<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PasswordResendRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['reset_id' => [
            'required',
            'string',
            Rule::exists('verification_codes', 'id')->where(fn ($query) => $query->where('type', 'password_reset')),
        ]];
    }
}
