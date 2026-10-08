<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class UpsertServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('patch');

        return [
            'category_id' => ['sometimes', 'nullable', 'ulid', 'exists:categories,id'],
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'image_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'max_people' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'is_bookable' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
            'price_minor' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'duration' => [$isUpdate ? 'sometimes' : 'required', 'date_format:H:i'],
        ];
    }
}
