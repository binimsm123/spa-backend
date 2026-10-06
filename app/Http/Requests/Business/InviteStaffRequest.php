<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class InviteStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mobile_number' => ['required', 'string'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'role' => ['required', 'string', 'in:owner,manager,staff'],
        ];
    }
}
