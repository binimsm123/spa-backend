<?php

namespace App\Http\Dto\Api;

use App\Models\Tip;

final readonly class TipCheckoutData
{
    public function __construct(private Tip $tip) {}

    public function toArray(): array
    {
        return ['payment_id' => $this->tip->payment_id, 'booking_id' => $this->tip->booking_id, 'provider' => $this->tip->payment?->gateway?->value, 'amount' => (int) round($this->tip->amount_minor / 100), 'currency' => $this->tip->currency, 'checkout_url' => $this->tip->payment?->checkout_url, 'gateway_data' => array_diff_key($this->tip->payment?->gateway_payload ?? [], ['verification' => true]), 'expires_in' => $this->tip->payment ? max(0, $this->tip->payment->created_at->getTimestamp() + 900 - now()->getTimestamp()) : 0, 'status' => $this->tip->status];
    }
}
