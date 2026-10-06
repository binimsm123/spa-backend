<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    /**
     * POST /webhooks/payments/{gateway}
     */
    public function payment(Request $request, string $gateway): JsonResponse
    {
        $gatewayEnum = PaymentGateway::tryFrom($gateway);

        if ($gatewayEnum === null) {
            return $this->success(null, 'Unknown gateway.', Response::HTTP_OK);
        }

        $signature = (string) $request->header('X-Webhook-Signature', '');

        $payment = $this->payments->handleWebhook($gatewayEnum, $signature, $request->all());

        return $this->success([
            'payment_id' => $payment->getKey(),
            'status' => $payment->status->value,
        ], 'Webhook processed.');
    }
}
