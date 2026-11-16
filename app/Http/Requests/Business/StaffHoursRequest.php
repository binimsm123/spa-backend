<?php

namespace App\Http\Requests\Business;

use App\Support\StaffBreaks;
use Illuminate\Validation\Validator;

class StaffHoursRequest extends StaffRequest
{
    public function rules(): array
    {
        if ($this->isMethodSafe()) {
            return [];
        }

        return [
            'hours' => ['required', 'array', 'list', 'size:7'],
            'hours.*' => ['required', 'array:weekday,opens_at,closes_at,breaks,is_closed'],
            'hours.*.weekday' => ['required', 'integer', 'between:0,6', 'distinct'],
            'hours.*.opens_at' => ['required_unless:hours.*.is_closed,true', 'nullable', 'date_format:H:i'],
            'hours.*.closes_at' => ['required_unless:hours.*.is_closed,true', 'nullable', 'date_format:H:i', 'after:hours.*.opens_at'],
            'hours.*.breaks' => ['exclude_if:hours.*.is_closed,true', 'sometimes', 'array', 'list', 'max:50'],
            'hours.*.breaks.*' => ['required', 'array:name,starts_at,ends_at'],
            'hours.*.breaks.*.name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'hours.*.breaks.*.starts_at' => ['required', 'date_format:H:i'],
            'hours.*.breaks.*.ends_at' => ['required', 'date_format:H:i', 'after:hours.*.breaks.*.starts_at'],
            'hours.*.is_closed' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->isMethodSafe() || $validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($this->validated('hours') as $dayIndex => $day) {
                if ($day['is_closed']) {
                    continue;
                }
                foreach (StaffBreaks::errors($day['breaks'] ?? [], $day['opens_at'], $day['closes_at'], 'hours.'.$dayIndex.'.breaks') as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'hours.size' => 'Provide one working-hours entry for each of the seven weekdays.',
            'hours.*.weekday.distinct' => 'Each weekday must appear only once.',
            'hours.*.opens_at.required_unless' => 'A start time is required for a working day.',
            'hours.*.closes_at.required_unless' => 'An end time is required for a working day.',
            'hours.*.closes_at.after' => 'The end time must be after the start time on the same day.',
            'hours.*.breaks.*.starts_at.required' => 'A start time is required for each break.',
            'hours.*.breaks.*.ends_at.required' => 'An end time is required for each break.',
            'hours.*.breaks.*.ends_at.after' => 'The break end time must be after its start time.',
        ];
    }
}
