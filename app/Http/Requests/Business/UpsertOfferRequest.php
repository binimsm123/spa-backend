<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('patch');

        return [
            'code' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:32', Rule::unique('offers', 'code')->ignore($this->route('offer'))],
            'title' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'discount_type' => [$isUpdate ? 'sometimes' : 'required', 'in:fixed,percentage'],
            'discount_value' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'min:1'],
            'minimum_booking_amount_minor' => ['sometimes', 'integer', 'min:0'],
            'max_discount_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'starts_at' => [$isUpdate ? 'sometimes' : 'required', 'date'],
            'expires_at' => [$isUpdate ? 'sometimes' : 'required', 'date', 'after:starts_at'],
            'status' => ['sometimes', 'in:active,paused,expired'],
            'total_usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_user_limit' => ['sometimes', 'integer', 'min:1'],
            'service_id' => ['sometimes', 'nullable', 'ulid', 'exists:services,id'],
            'location_id' => ['sometimes', 'nullable', 'ulid', 'exists:locations,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }
}
