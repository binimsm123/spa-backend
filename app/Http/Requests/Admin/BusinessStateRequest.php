<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BusinessStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:verify,reject,suspend,reactivate,online,offline'],
            'reason' => ['required_if:action,suspend,reject', 'nullable', 'string', 'max:500'],
        ];
    }
}
