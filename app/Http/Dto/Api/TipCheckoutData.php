<?php

namespace App\Http\Dto\Api;

use App\Models\Tip;

final readonly class TipCheckoutData
{
    public function __construct(private Tip $tip) {}

    public function toArray(): array
    {
        return ['payment_id' => $this->tip->payment_id, 'booking_id' => $this->tip->booking_id, 'provider' => $this->tip->payment?->gateway?->value, 'amount' => (int) round($this->tip->amount_minor / 100), 'currency' => $this->tip->currency, 'checkout_url' => $this->tip->payment?->checkout_url, 'expires_in' => 900, 'status' => $this->tip->status];
    }
}
