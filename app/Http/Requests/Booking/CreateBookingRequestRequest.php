<?php

namespace App\Http\Requests\Booking;

use App\Models\Business;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateBookingRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $businessId = $this->input('business_id');
        $business = is_string($businessId) ? Business::query()->find($businessId) : null;

        return [
            'service_id' => ['required', 'ulid'],
            'service_price_id' => ['sometimes', 'ulid', Rule::exists('service_prices', 'id')
                ->where('service_id', $this->input('service_id'))->where('is_current', true)],
            'business_id' => ['required', 'ulid', 'exists:businesses,id'],
            'requested_date' => ['required', 'date', 'after_or_equal:'.now($business?->timezone)->toDateString()],
            'people_count' => ['nullable', 'integer', 'min:1', 'max:20'],
            'timezone' => ['nullable', 'timezone:all'],
        ];
    }
}
