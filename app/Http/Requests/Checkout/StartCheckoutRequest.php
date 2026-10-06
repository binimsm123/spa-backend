<?php

namespace App\Http\Requests\Checkout;

use Illuminate\Foundation\Http\FormRequest;

class StartCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quote_id' => ['required', 'ulid'],
            'gateway' => ['required', 'string', 'in:esewa,khalti,mypay'],
        ];
    }
}
