<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class UpsertClosuresRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'closures' => ['sometimes', 'array'],
            'closures.*.starts_on' => ['required', 'date'],
            'closures.*.ends_on' => ['required', 'date', 'after_or_equal:closures.*.starts_on'],
            'closures.*.reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
