<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentStatus;
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

    /** @param array<string, string> $parameters */
    private function redirectToFrontend(array $parameters): RedirectResponse
    {
        $query = http_build_query($parameters);
        $frontendUrl = (string) config('services.frontend.url');

        return redirect()->away($frontendUrl.(str_contains($frontendUrl, '?') ? '&' : '?').$query);
    }
}
