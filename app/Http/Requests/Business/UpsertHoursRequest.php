<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class UpsertHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hours' => ['required', 'array', 'size:7'],
            'hours.*.weekday' => ['required', 'integer', 'between:0,6'],
            'hours.*.opens_at' => ['required_unless:hours.*.is_closed,true', 'nullable', 'date_format:H:i'],
            'hours.*.closes_at' => ['required_unless:hours.*.is_closed,true', 'nullable', 'date_format:H:i'],
            'hours.*.is_closed' => ['required', 'boolean'],
        ];
    }
}
