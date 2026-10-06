<?php

namespace App\Http\Requests\Checkout;

use Illuminate\Foundation\Http\FormRequest;

class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tip_minor' => ['nullable', 'integer', 'min:0'],
            'promo_code' => ['nullable', 'string', 'max:32'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('promo_code')) {
            $this->merge(['promo_code' => strtoupper(trim((string) $this->input('promo_code')))]);
        }
    }
}
