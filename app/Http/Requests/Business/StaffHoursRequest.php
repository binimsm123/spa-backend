<?php

namespace App\Http\Requests\Business;

class StaffHoursRequest extends StaffRequest
{
    public function rules(): array
    {
        if ($this->isMethodSafe()) {
            return [];
        }

        return [
            'hours' => ['required', 'array', 'list', 'size:7'],
            'hours.*' => ['required', 'array:weekday,opens_at,closes_at,is_closed'],
            'hours.*.weekday' => ['required', 'integer', 'between:0,6', 'distinct'],
            'hours.*.opens_at' => ['required_unless:hours.*.is_closed,true', 'nullable', 'date_format:H:i'],
            'hours.*.closes_at' => ['required_unless:hours.*.is_closed,true', 'nullable', 'date_format:H:i', 'after:hours.*.opens_at'],
            'hours.*.is_closed' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'hours.size' => 'Provide one working-hours entry for each of the seven weekdays.',
            'hours.*.weekday.distinct' => 'Each weekday must appear only once.',
            'hours.*.opens_at.required_unless' => 'A start time is required for a working day.',
            'hours.*.closes_at.required_unless' => 'An end time is required for a working day.',
            'hours.*.closes_at.after' => 'The end time must be after the start time on the same day.',
        ];
    }
}
