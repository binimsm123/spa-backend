<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class UpsertBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('patch');

        return [
            'branch_name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:120'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'location_id' => ['sometimes', 'nullable', 'ulid', 'exists:locations,id'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'timezone' => ['sometimes', 'nullable', 'timezone:all'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
