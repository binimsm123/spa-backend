<?php

namespace App\Services\Payments;

use App\Enums\PaymentGateway as Gateway;
use App\Support\ApiException;
use App\Support\ErrorCode;

class PaymentGatewayFactory
{
    public function __construct(
        private readonly EsewaGateway $esewa,
        private readonly KhaltiGateway $khalti,
    ) {}

    public function make(Gateway $gateway): PaymentGateway
    {
        return match ($gateway) {
            Gateway::ESewa => $this->esewa,
            Gateway::Khalti => $this->khalti,
            default => throw new ApiException(ErrorCode::PaymentGatewayError, 'This payment gateway is not available yet.'),
        };
    }

    public function available(Gateway $gateway): bool
    {
        return match ($gateway) {
            Gateway::ESewa => $this->esewa->available(),
            Gateway::Khalti => $this->khalti->available(),
            default => false,
        };
    }
}
