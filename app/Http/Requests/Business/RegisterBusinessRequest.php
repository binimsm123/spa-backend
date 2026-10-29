<?php

namespace App\Http\Requests\Business;

use App\Models\Business;
use App\Models\User;
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
        // dd($this->request->all());
        $rules = [
            'category_ids' => ['sometimes', 'array', Rule::exists('categories', 'id')],
            'category_ids.*' => ['required', 'ulid', 'distinct'],
            'name' => ['required', 'string', 'max:160'],
            'signup_from' => ['sometimes', 'nullable', 'string', 'in:web,android,ios'],
            'dob' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'hero_image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'phone_number' => ['required', 'string', 'max:32'],
            // Registration addresses are supplied exclusively through locations.
            // 'address' => ['required_without:locations', 'string', 'max:255'],
            // 'city' => ['required_without:locations', 'string', 'max:120'],
            // 'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            // 'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'locations' => ['required', 'array', 'min:1'],
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
            'personal_kyc_document' => ['sometimes', 'nullable', 'array:'.implode(',', User::KYC_DOCUMENT_TYPES)],
        ];

        foreach (Business::KYC_DOCUMENT_TYPES as $type) {
            $rules[$type] = ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'];
        }

        foreach (User::KYC_DOCUMENT_TYPES as $type) {
            $rules['personal_kyc_document.'.$type] = [
                Rule::requiredIf(($this->all()['personal_kyc_document'] ?? null) !== null),
                'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120',
            ];
        }

        return $rules;
    }
}
