<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class UpsertBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('patch');

        return [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:160'],
            'about' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'hero_image_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'is_online' => ['sometimes', 'boolean'],
            'timezone' => ['sometimes', 'nullable', 'timezone:all'],
        ];
    }
}
