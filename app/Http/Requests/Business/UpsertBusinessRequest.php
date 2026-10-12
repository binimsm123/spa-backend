<?php

namespace App\Http\Requests\Business;

use App\Models\Business;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('patch');

        $rules = [
            'category_ids' => ['sometimes', 'array', Rule::exists('categories', 'id')],
            'category_ids.*' => ['required', 'ulid', 'distinct'],
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:160'],
            'about' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'hero_image_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hero_image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'prohibits:hero_image_path'],
            'kyc_documents' => ['sometimes', 'array:'.implode(',', Business::KYC_DOCUMENT_TYPES)],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'is_online' => ['sometimes', 'boolean'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'locations' => ['sometimes', 'array', 'min:1'],
            'locations.*' => ['required', 'array:address,city,latitude,longitude,service_availability'],
            'locations.*.address' => ['required', 'string', 'max:255'],
            'locations.*.city' => ['required', 'string', 'max:120'],
            'locations.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'locations.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'locations.*.service_availability' => ['required', 'in:home,on_site'],
            'timezone' => ['sometimes', 'timezone:all'],
            'home_service' => ['sometimes', 'boolean'],
            'location_service' => ['sometimes', 'boolean'],
            'staff_number' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
        ];

        foreach (Business::KYC_DOCUMENT_TYPES as $type) {
            $rules[$type] = ['sometimes', 'required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120', 'prohibits:kyc_documents.'.$type];
            $rules['kyc_documents.'.$type] = ['sometimes', 'required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'];
        }

        return $rules;
    }
}
