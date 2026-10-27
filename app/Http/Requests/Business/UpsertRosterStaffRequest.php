<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class UpsertRosterStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('mobile_number')) {
            $phone = preg_replace('/[\s()\-]/', '', trim((string) $this->input('mobile_number')));
            if (preg_match('/^9\d{9}$/', $phone)) {
                $phone = '+977'.$phone;
            }
            $this->merge(['mobile_number' => $phone]);
        }
    }

    public function rules(): array
    {
        $required = $this->isMethod('patch') ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'required', 'string', 'max:100'],
            'job_title' => [$required, 'required', 'string', 'max:80'],
            'mobile_number' => [$required, 'required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'avatar' => ['sometimes', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120', function ($attribute, $value, $fail): void {
                if ($value instanceof UploadedFile && $value->getSize() <= 0) {
                    $fail('Choose a nonempty image.');
                }
            }],
            'remove_avatar' => ['sometimes', 'boolean'],
        ];
    }
}
