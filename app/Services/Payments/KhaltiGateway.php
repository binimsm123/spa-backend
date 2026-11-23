<?php

namespace App\Services\Payments;

use App\Enums\PaymentGateway as Gateway;
use App\Models\Payment;
use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class KhaltiGateway implements PaymentGateway
{
    public function available(): bool
    {
        return filled(config('services.khalti.secret'));
    }

    public function initiate(Payment $payment): array
    {
        if ($payment->currency !== 'NPR' || $payment->amount_minor <= 0) {
            throw ApiException::validation('Khalti requires a positive payment amount in NPR.');
        }

        $response = $this->post('initiate_url', [
            'return_url' => route('payments.khalti.callback'),
            'website_url' => config('services.khalti.website_url'),
            'amount' => $payment->amount_minor,
            'purchase_order_id' => $payment->gateway_reference,
            'purchase_order_name' => 'SPA '.$payment->purpose->value.' '.$payment->booking_id,
        ]);

        $pidx = $response->json('pidx');
        $url = $response->json('payment_url');
        if (! $response->successful() || ! is_string($pidx) || blank($pidx)
            || ! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new ApiException(ErrorCode::PaymentGatewayError, 'Khalti could not start the payment.');
        }

        return ['pidx' => $pidx, 'payment_url' => $url, 'expires_in' => $response->json('expires_in')];
    }

    /** @return array{payment: Payment, verification: array} */
    public function verifyCallback(string $reference, string $pidx): array
    {
        $payment = Payment::query()
            ->where('gateway', Gateway::Khalti->value)
            ->where('gateway_reference', $reference)
            ->firstOrFail();

        if ($pidx !== ($payment->gateway_payload['pidx'] ?? null)) {
            throw new ApiException(ErrorCode::PaymentGatewayError, 'The Khalti payment reference does not match.');
        }

        $response = $this->post('lookup_url', ['pidx' => $pidx]);
        $verification = $response->json();
        if (! is_array($verification) || ($verification['pidx'] ?? null) !== $pidx
            || ($verification['total_amount'] ?? null) !== $payment->amount_minor) {
            throw new ApiException(ErrorCode::PaymentGatewayError, 'The Khalti payment details do not match.');
        }

        $status = $verification['status'] ?? null;
        if (in_array($status, ['Pending', 'Initiated', 'Expired', 'User canceled', 'Failed', 'Refunded', 'Partially Refunded'], true)) {
            return ['payment' => $payment, 'verification' => $verification];
        }

        if (! $response->successful() || $status !== 'Completed'
            || ($verification['refunded'] ?? null) !== false
            || ! is_string($verification['transaction_id'] ?? null) || blank($verification['transaction_id'])) {
            throw new ApiException(ErrorCode::PaymentGatewayError, 'Khalti has not verified this payment as complete.');
        }

        return ['payment' => $payment, 'verification' => $verification];
    }

    private function post(string $endpoint, array $data): Response
    {
        if (! $this->available()) {
            throw new ApiException(ErrorCode::PaymentGatewayError, 'Khalti payments are not configured.');
        }

        try {
            return Http::acceptJson()
                ->withToken((string) config('services.khalti.secret'), 'Key')
                ->withoutRedirecting()
                ->connectTimeout(5)
                ->timeout(10)
                ->post(config('services.khalti.'.$endpoint), $data);
        } catch (ConnectionException $exception) {
            throw new ApiException(ErrorCode::PaymentGatewayError, 'Khalti could not be reached. Please try again.', previous: $exception);
        }
    }
}
