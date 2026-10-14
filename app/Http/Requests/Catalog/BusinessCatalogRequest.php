<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class BusinessCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['home_service', 'location_service', 'verified_only'] as $field) {
            if (in_array($this->input($field), ['true', 'false'], true)) {
                $this->merge([$field => $this->input($field) === 'true']);
            }
        }
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'required_with:longitude,radius_km', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude,radius_km', 'numeric', 'between:-180,180'],
            'radius_km' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'home_service' => ['sometimes', 'boolean'],
            'location_service' => ['sometimes', 'boolean'],
            'verified_only' => ['sometimes', 'boolean'],
            'category_id' => ['sometimes', 'ulid'],
            'business_id' => ['sometimes', 'ulid'],
            'sort' => ['sometimes', 'in:rating,name,newest'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'business_page' => ['sometimes', 'integer', 'min:1'],
            'businesses_per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'service_page' => ['sometimes', 'integer', 'min:1'],
            'services_per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
