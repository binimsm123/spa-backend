<?php

namespace App\Http\Requests\Review;

use Illuminate\Foundation\Http\FormRequest;

class SubmitReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'tag' => ['nullable', 'string', 'max:64'],
            'comments' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
