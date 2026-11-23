<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Business\UpsertOfferRequest;
use Illuminate\Validation\Rule;

class UpsertAdminOfferRequest extends UpsertOfferRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $offer = $this->route('offer');
        $businessId = $offer ? $offer->business_id : $this->input('business_id');

        if ($offer === null) {
            $rules['business_id'] = ['sometimes', 'nullable', 'bail', 'ulid', 'exists:businesses,id'];
        }

        if (is_string($businessId)) {
            $rules['service_id'] = [
                'sometimes', 'nullable', 'bail', 'ulid',
                Rule::exists('services', 'id')->where('business_id', $businessId),
            ];
        }

        return $rules;
    }
}
