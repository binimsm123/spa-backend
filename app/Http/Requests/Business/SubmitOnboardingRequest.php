<?php

namespace App\Http\Requests\Business;

use App\Models\BusinessOnboarding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class SubmitOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        $cities = ['Kathmandu', 'Lalitpur', 'Bhaktapur', 'Pokhara', 'Chitwan'];
        $rules = [
            'idempotency_key' => ['required', 'string', 'max:128'],
            'full_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'mobile_number' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'pan_number' => ['required', 'string', 'max:20'],
            'business_name' => ['required', 'string', 'max:120'],
            'signup_from' => ['sometimes', 'nullable', 'string', 'in:web,android,ios'],
            'outlet_count' => ['required', Rule::in(['1', '2', '3', '4', '5', '6+'])],
            'employee_range' => ['required', Rule::in(['solo', '2_5', '6_10', '11_25', '26_plus'])],
            'service_area' => ['required', Rule::in($cities)],
            'home_service_location' => ['nullable', Rule::in($cities)],
        ];
        foreach (BusinessOnboarding::DOCUMENTS as $kind) {
            $rules[$kind] = [$this->routeIs('business.onboarding.resubmit') ? 'sometimes' : 'required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120', function ($attribute, $value, $fail): void {
                if ($value instanceof UploadedFile && $value->getSize() <= 0) {
                    $fail('Choose a nonempty document.');
                }
            }];
        }

        return $rules;
    }
}
