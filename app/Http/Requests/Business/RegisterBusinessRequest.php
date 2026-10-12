<?php

namespace App\Http\Requests\Business;

use App\Models\Business;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterBusinessRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'category_ids' => ['sometimes', 'array', Rule::exists('categories', 'id')],
            'category_ids.*' => ['required', 'ulid', 'distinct'],
            'name' => ['required', 'string', 'max:160'],
            'hero_image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'phone_number' => ['required', 'string', 'max:32'],
            'address' => ['required_without:locations', 'string', 'max:255'],
            'city' => ['required_without:locations', 'string', 'max:120'],
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
            'is_insured' => ['sometimes', 'boolean'],
        ];

        foreach (Business::KYC_DOCUMENT_TYPES as $type) {
            $rules[$type] = ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'];
        }

        return $rules;
    }
}
