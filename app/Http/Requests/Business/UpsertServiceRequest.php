<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class UpsertServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['prices', 'retained_photo_ids'] as $field) {
            if (is_string($this->input($field))) {
                $decoded = json_decode($this->input($field), true);
                if (is_array($decoded)) {
                    $this->merge([$field => $decoded]);
                }
            }
        }
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('patch');
        $modern = $this->input('management_version') === '1' || $this->input('management_version') === 1 || $this->has('prices') || $this->hasFile('images');
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'management_version' => ['sometimes', 'in:1'],
            'category_id' => [$modern ? $required : 'sometimes', $modern ? 'required' : 'nullable', 'ulid', 'exists:categories,id'],
            'name' => [$required, 'required', 'string', 'max:'.($modern ? 120 : 160)],
            'description' => [$modern ? $required : 'sometimes', $modern ? 'required' : 'nullable', 'string', 'max:'.($modern ? 1000 : 4000)],
            'image_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'max_people' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'is_bookable' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
            'price_minor' => [$modern ? 'prohibited' : $required, 'integer', 'min:0'],
            'currency' => [$modern ? 'prohibited' : 'sometimes', 'string', 'size:3'],
            'duration' => [$modern ? 'prohibited' : $required, 'date_format:H:i'],
            'prices' => [$modern ? $required : 'sometimes', 'array', 'min:1', 'max:100'],
            'prices.*.duration_minutes' => ['required', 'integer', 'min:1', 'max:1439', 'distinct'],
            'prices.*.price_minor' => ['required', 'integer', 'min:1', 'max:9999999900'],
            'prices.*.currency' => ['required', Rule::in(['NPR'])],
            'images' => [$modern && ! $isUpdate ? 'required' : 'sometimes', 'array', 'max:2'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png', 'max:5120', function ($attribute, $value, $fail): void {
                if ($value instanceof UploadedFile && $value->getSize() <= 0) {
                    $fail('Choose a nonempty image.');
                }
            }],
            'retained_photo_ids' => ['sometimes', 'array', 'max:2'],
            'retained_photo_ids.*' => ['required', 'ulid', 'distinct'],
        ];
    }
}
