<?php

namespace App\Services\Payments;

use App\Models\Payment;

interface PaymentGateway
{
    /** @return array<string, mixed> */
    public function initiate(Payment $payment): array;
}
