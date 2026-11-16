<?php

namespace App\Http\Requests\Business;

class StaffBreakRequest extends StaffRequest
{
    public function rules(): array
    {
        $rules = ['weekday' => ['required', 'integer', 'between:0,6']];
        if ($this->isMethodSafe()) {
            return $rules;
        }

        return $rules + [
            'breaks' => [$this->isMethod('post') ? 'required' : 'present', 'array', 'list', 'max:50'],
            'breaks.*' => ['required', 'array:name,starts_at,ends_at'],
            'breaks.*.name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'breaks.*.starts_at' => ['required', 'date_format:H:i'],
            'breaks.*.ends_at' => ['required', 'date_format:H:i', 'after:breaks.*.starts_at'],
        ];
    }

    public function messages(): array
    {
        return [
            'breaks.*.starts_at.required' => 'A start time is required for each break.',
            'breaks.*.ends_at.required' => 'An end time is required for each break.',
            'breaks.*.ends_at.after' => 'The break end time must be after its start time.',
        ];
    }
}
