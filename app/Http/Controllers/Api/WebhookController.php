<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentStatus;
use App\Services\Payments\KhaltiGateway;
use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function esewaSuccess(Request $request): RedirectResponse
    {
        $payment = $this->payments->complete(
            $request->attributes->get('payment'),
            $request->attributes->get('verification'),
        );

        return $this->redirectToFrontend([
            'success' => $payment->status === PaymentStatus::Succeeded ? 'true' : 'false',
            'booking_id' => $payment->booking_id,
            'payment_id' => $payment->getKey(),
        ]);
    }

    public function esewaFailure(): RedirectResponse
    {
        return $this->redirectToFrontend(['success' => 'false']);
    }

    public function khaltiCallback(Request $request, KhaltiGateway $gateway): RedirectResponse
    {
        $data = $request->validate([
            'purchase_order_id' => ['required', 'ulid'],
            'pidx' => ['required', 'string', 'max:255'],
        ]);
        $verified = $gateway->verifyCallback($data['purchase_order_id'], $data['pidx']);
        $payment = $verified['payment'];
        $verification = $verified['verification'];
        if ($verification['status'] === 'Completed') {
            $payment = $this->payments->complete($payment, $verification);
        } elseif (in_array($verification['status'], ['Expired', 'User canceled', 'Failed'], true)) {
            $this->payments->fail($payment);
        }

        return $this->redirectToFrontend([
            'success' => $verification['status'] === 'Completed' && $payment->status === PaymentStatus::Succeeded ? 'true' : 'false',
            'booking_id' => $payment->booking_id,
            'payment_id' => $payment->getKey(),
        ]);
    }

    /** @param array<string, string> $parameters */
    private function redirectToFrontend(array $parameters): RedirectResponse
    {
        $query = http_build_query($parameters);
        $frontendUrl = (string) config('services.frontend.url');

        return redirect()->away($frontendUrl.(str_contains($frontendUrl, '?') ? '&' : '?').$query);
    }
}
