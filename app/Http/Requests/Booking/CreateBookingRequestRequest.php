<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

class CreateBookingRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_id' => ['required', 'ulid'],
            'business_location_id' => ['required', 'ulid'],
            'requested_date' => ['required', 'date', 'after_or_equal:today'],
            'people_count' => ['nullable', 'integer', 'min:1', 'max:20'],
            'timezone' => ['nullable', 'timezone:all'],
        ];
    }
}
