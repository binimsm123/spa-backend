<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookingStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['confirmed', 'in_progress', 'completed', 'cancelled', 'refunded'])],
            'note' => ['nullable', 'string', 'max:255'],
            'staff_user_id' => ['nullable', 'ulid', 'exists:users,id'],
        ];
    }
}
