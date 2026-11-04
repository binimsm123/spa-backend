<?php

namespace App\Http\Requests\Business;

use App\Models\StaffLeave;
use App\Support\ApiException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StaffLeaveRequest extends StaffRequest
{
    public function authorize(): bool
    {
        parent::authorize();
        $leave = $this->route('leave');

        if ($leave instanceof StaffLeave && $leave->business_user_id !== $this->staffMembership()->getKey()) {
            throw ApiException::notFound('Staff leave not found in this business.');
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $leave = $this->route('leave');

        if ($this->isMethod('patch') && $leave instanceof StaffLeave) {
            $this->merge(array_replace([
                'starts_on' => $leave->starts_on->toDateString(),
                'ends_on' => $leave->ends_on->toDateString(),
                'starts_at' => $leave->starts_at?->format('H:i'),
                'ends_at' => $leave->ends_at?->format('H:i'),
                'reason' => $leave->reason,
            ], $this->all()));
        }
    }

    public function rules(): array
    {
        if ($this->isMethodSafe()) {
            return [
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'between:1,100'],
                'from' => ['sometimes', 'date_format:Y-m-d'],
                'to' => ['sometimes', 'date_format:Y-m-d', Rule::when($this->filled('from'), ['after_or_equal:from'])],
            ];
        }

        if ($this->isMethod('delete')) {
            return [];
        }

        $prefix = $this->isMethod('post') ? 'leaves.*.' : '';
        $rules = [
            $prefix.'starts_on' => ['required', 'date_format:Y-m-d'],
            $prefix.'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$prefix.'starts_on'],
            $prefix.'starts_at' => ['required_with:'.$prefix.'ends_at', 'nullable', 'date_format:H:i'],
            $prefix.'ends_at' => ['required_with:'.$prefix.'starts_at', 'nullable', 'date_format:H:i', 'after:'.$prefix.'starts_at'],
            $prefix.'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];

        if ($this->isMethod('post')) {
            $rules['leaves'] = ['required', 'array', 'list', 'min:1', 'max:100'];
            $rules['leaves.*'] = ['required', 'array:starts_on,ends_on,starts_at,ends_at,reason'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->isMethodSafe() || $this->isMethod('delete') || $validator->errors()->isNotEmpty()) {
                return;
            }

            $rows = $this->isMethod('post') ? $this->input('leaves') : [$this->only(['starts_on', 'ends_on', 'starts_at', 'ends_at'])];

            foreach ($rows as $index => $row) {
                if (isset($row['starts_at']) && $row['starts_on'] !== $row['ends_on']) {
                    $field = $this->isMethod('post') ? 'leaves.'.$index.'.ends_on' : 'ends_on';
                    $validator->errors()->add($field, 'Partial-day leave must start and end on the same date.');
                }
            }
        }];
    }

    public function messages(): array
    {
        $prefix = $this->isMethod('post') ? 'leaves.*.' : '';

        return [
            $prefix.'ends_on.after_or_equal' => 'The leave end date must be on or after the start date.',
            $prefix.'starts_at.required_with' => 'A start time is required for partial-day leave.',
            $prefix.'ends_at.required_with' => 'An end time is required for partial-day leave.',
            $prefix.'ends_at.after' => 'The leave end time must be after the start time.',
        ];
    }
}
