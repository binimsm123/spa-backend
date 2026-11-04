<?php

namespace App\Http\Requests\Business;

use App\Enums\BusinessUserRole;
use App\Support\ApiException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffServiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $business = $this->route('business');
        $membership = $this->user()->membershipFor($business);

        abort_unless($membership !== null, 403, 'You do not manage this business.');

        if (! $this->isMethodSafe()) {
            abort_unless(
                in_array($membership->role, [BusinessUserRole::Owner->value, BusinessUserRole::Manager->value], true),
                403,
                'Your role cannot perform this action.',
            );
        }

        if (! $this->route('user')->businessMemberships()->where('business_id', $business->getKey())->exists()) {
            throw ApiException::notFound('Staff user not found in this business.');
        }

        return true;
    }

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
