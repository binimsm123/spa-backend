<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class InviteStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $phone = $this->input('mobile_number');

        if (is_string($phone)) {
            $phone = preg_replace('/[\s()\-]/', '', trim($phone));

            if (preg_match('/\A9[6-8][0-9]{8}\z/', $phone)) {
                $phone = '+977'.$phone;
            }

            $this->merge(['mobile_number' => $phone]);
        }
    }

    public function rules(): array
    {
        return [
            'mobile_number' => ['required', 'string', 'regex:/\A\+9779[6-8][0-9]{8}\z/'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:80'],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).+$/'],
            'role' => ['required', 'string', 'in:owner,manager,staff'],
        ];
    }

    public function messages(): array
    {
        return [
            'mobile_number.regex' => 'Enter a valid Nepal mobile number with 10 digits starting with 96, 97, or 98, optionally prefixed with +977.',
            'password.regex' => 'The password needs uppercase, lowercase, a number, and a symbol.',
        ];
    }
}
