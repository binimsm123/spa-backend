<?php

namespace App\Services\Payments;

use App\Enums\PaymentGateway as Gateway;
use App\Models\Payment;
use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use JsonException;

class EsewaGateway implements PaymentGateway
{
    private const string RequestFields = 'total_amount,transaction_uuid,product_code';

    private const string ResponseFields = 'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names';

    public function available(): bool
    {
        return filled(config('services.esewa.product_code')) && filled(config('services.esewa.secret'));
    }

    public function initiate(Payment $payment): array
    {
        $this->assertAvailable();

        if ($payment->currency !== 'NPR' || $payment->amount_minor <= 0) {
            throw ApiException::validation('eSewa requires a positive payment amount in NPR.');
        }

        $amount = intdiv($payment->amount_minor, 100).'.'.str_pad((string) ($payment->amount_minor % 100), 2, '0', STR_PAD_LEFT);
        $fields = [
            'amount' => $amount,
            'tax_amount' => '0',
            'total_amount' => $amount,
            'transaction_uuid' => $payment->gateway_reference,
            'product_code' => (string) config('services.esewa.product_code'),
            'product_service_charge' => '0',
            'product_delivery_charge' => '0',
            'success_url' => route('payments.esewa.success'),
            'failure_url' => route('payments.esewa.failure'),
            'signed_field_names' => self::RequestFields,
        ];
        $fields['signature'] = $this->signature($fields, self::RequestFields);

        return $fields;
    }

    /** @return array{payment: Payment, verification: array<string, mixed>} */
    public function verifyCallback(string $data): array
    {
        $callback = $this->decodeCallback($data);
        $payment = Payment::query()->where('gateway', Gateway::ESewa->value)
            ->where('gateway_reference', $callback['transaction_uuid'])->first();

        if ($payment === null) {
            throw ApiException::notFound('Unknown payment reference.');
        }

        $productCode = $payment->gateway_payload['product_code'] ?? null;
        $amount = $payment->gateway_payload['total_amount'] ?? null;

        if (! is_string($productCode) || ! is_string($amount)) {
            throw new ApiException(ErrorCode::PaymentGatewayError, 'This payment has no eSewa checkout details.');
        }

        $this->assertMatchesPayment($payment, $callback, $productCode);

        if ($callback['status'] !== 'COMPLETE' || $callback['transaction_code'] === '') {
            throw new ApiException(ErrorCode::PaymentGatewayError, 'Invalid eSewa payment response.');
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(10)
                ->get((string) config('services.esewa.status_url'), [
                    'product_code' => $productCode,
                    'transaction_uuid' => $payment->gateway_reference,
                    'total_amount' => $amount,
                ])->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw new ApiException(ErrorCode::PaymentGatewayError, 'Unable to verify the payment with eSewa. Please try again.', previous: $exception);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new ApiException(ErrorCode::PaymentGatewayError, 'Invalid eSewa verification response.');
        }

        $this->assertMatchesPayment($payment, $payload, $productCode);
        if (($payload['status'] ?? null) !== 'COMPLETE'
            || ! is_string($payload['ref_id'] ?? null)
            || $payload['ref_id'] === ''
            || $payload['ref_id'] !== $callback['transaction_code']) {
            throw new ApiException(ErrorCode::PaymentGatewayError, 'The eSewa transaction is not verified as complete.');
        }

        return ['payment' => $payment, 'verification' => $payload];
    }

    /** @return array<string, string> */
    private function decodeCallback(string $data): array
    {
        $this->assertAvailable();
        $decoded = base64_decode($data, true);

        try {
            $payload = $decoded !== false ? json_decode($decoded, true, 16, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $payload = null;
        }

        if (! is_array($payload) || ($payload['signed_field_names'] ?? null) !== self::ResponseFields) {
            throw new ApiException(ErrorCode::PaymentGatewayError, 'Invalid eSewa callback data.');
        }

        foreach ([...explode(',', self::ResponseFields), 'signature'] as $field) {
            if (! is_string($payload[$field] ?? null)) {
                throw new ApiException(ErrorCode::PaymentGatewayError, 'Invalid eSewa callback data.');
            }
        }

        if (! hash_equals($this->signature($payload, self::ResponseFields), $payload['signature'])) {
            throw new ApiException(ErrorCode::PaymentGatewayError, 'Invalid eSewa callback signature.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function assertMatchesPayment(Payment $payment, array $payload, string $productCode): void
    {
        if (($payload['transaction_uuid'] ?? null) !== $payment->gateway_reference
            || ($payload['product_code'] ?? null) !== $productCode
            || $this->amountMinor($payload['total_amount'] ?? null) !== $payment->amount_minor) {
            throw new ApiException(ErrorCode::PaymentGatewayError, 'The eSewa payment details do not match.');
        }
    }

    private function amountMinor(mixed $amount): ?int
    {
        if (! is_string($amount) && ! is_int($amount) && ! is_float($amount)) {
            return null;
        }

        $amount = (string) $amount;

        if (! preg_match('/^(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d{1,2})?$/D', $amount)) {
            return null;
        }

        $parts = explode('.', str_replace(',', '', $amount));

        if (strlen($parts[0]) > 12) {
            return null;
        }

        return ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    /** @param array<string, string> $payload */
    private function signature(array $payload, string $fields): string
    {
        $message = implode(',', array_map(fn (string $field): string => $field.'='.$payload[$field], explode(',', $fields)));

        return base64_encode(hash_hmac('sha256', $message, (string) config('services.esewa.secret'), true));
    }

    private function assertAvailable(): void
    {
        if (! $this->available()) {
            throw new ApiException(ErrorCode::PaymentGatewayError, 'eSewa payments are not configured.');
        }
    }
}
