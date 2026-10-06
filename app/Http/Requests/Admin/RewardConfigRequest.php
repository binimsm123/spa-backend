<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RewardConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'points_per_rupee' => ['required', 'numeric', 'min:0', 'max:1000'],
            'basis' => ['required', 'in:subtotal,post_discount,tax_inclusive,total'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
