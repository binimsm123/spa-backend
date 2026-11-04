<?php

namespace App\Http\Requests\Business;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class StaffServiceRequest extends StaffRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->isMethodSafe()) {
            return [
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'between:1,100'],
            ];
        }

        if ($this->isMethod('delete')) {
            return [];
        }

        return [
            'service_ids' => [$this->isMethod('post') ? 'required' : 'present', 'array', 'list'],
            'service_ids.*' => [
                'bail', 'required', 'ulid', 'distinct:ignore_case',
                Rule::exists('services', 'id')->where('business_id', $this->route('business')->getKey())->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'service_ids.*.exists' => 'The selected service was not found in this business.',
        ];
    }
}
