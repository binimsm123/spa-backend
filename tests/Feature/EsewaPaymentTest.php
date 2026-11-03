<?php

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\RewardConfiguration;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'services.frontend.url' => 'https://spa.example/booking-history',
        'services.esewa.product_code' => 'EPAYTEST',
        'services.esewa.secret' => 'test-esewa-secret',
        'services.esewa.checkout_url' => 'https://rc-epay.esewa.com.np/api/epay/main/v2/form',
        'services.esewa.status_url' => 'https://rc.esewa.com.np/api/epay/transaction/status/',
    ]);
});

/** @return array{0: User, 1: Booking, 2: Payment} */
function createEsewaCheckout(): array
{
    $user = User::factory()->create();
    [$business] = createBusinessWithService();
    $booking = Booking::query()->create([
        'user_id' => $user->getKey(),
        'business_id' => $business->getKey(),
        'appointment_date' => now()->addDay()->toDateString(),
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'status' => BookingStatus::AwaitingPayment,
        'subtotal_minor' => 500000,
        'tax_minor' => 25,
        'total_minor' => 500025,
        'currency' => 'NPR',
    ]);
    test()->actingAs($user, 'sanctum');
    $quote = test()->postJson("/api/v1/bookings/{$booking->id}/checkout/quote", ['tip_minor' => 100])
        ->assertOk()->json('data.quote_id');
    $response = test()->postJson("/api/v1/bookings/{$booking->id}/checkout", [
        'quote_id' => $quote, 'gateway' => 'esewa',
    ])->assertOk()->assertJsonPath('data.status', 'pending');

    return [$user, $booking, Payment::query()->findOrFail($response->json('data.payment_id'))];
}

/** @param array<string, mixed> $overrides */
function esewaCallbackData(Payment $payment, array $overrides = []): string
{
    $payload = array_merge([
        'transaction_code' => '0001TS9',
        'status' => 'COMPLETE',
        'total_amount' => '5,001.25',
        'transaction_uuid' => $payment->gateway_reference,
        'product_code' => 'EPAYTEST',
        'signed_field_names' => 'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names',
    ], $overrides);
    $message = implode(',', array_map(fn (string $field): string => $field.'='.$payload[$field], explode(',', $payload['signed_field_names'])));
    $payload['signature'] = base64_encode(hash_hmac('sha256', $message, 'test-esewa-secret', true));

    return base64_encode(json_encode($payload));
}

/** @param array<string, mixed> $overrides */
function fakeEsewaStatus(Payment $payment, array $overrides = []): void
{
    Http::fake([
        'rc.esewa.com.np/api/epay/transaction/status/*' => Http::response(array_merge([
            'transaction_uuid' => $payment->gateway_reference,
            'product_code' => 'EPAYTEST',
            'total_amount' => 5001.25,
            'status' => 'COMPLETE',
            'ref_id' => '0001TS9',
        ], $overrides)),
    ]);
}

/** @param array<string, mixed> $overrides */
function esewaSuccessCallback(Payment $payment, array $overrides = []): string
{
    return route('payments.esewa.success', ['data' => esewaCallbackData($payment, $overrides)]);
}

it('returns signed gateway data directly for the frontend to submit to eSewa', function (): void {
    [$user, $booking, $payment] = createEsewaCheckout();
    $response = $this->postJson("/api/v1/bookings/{$booking->id}/checkout", [
        'quote_id' => $booking->quote->id, 'gateway' => 'esewa',
    ])->assertOk()
        ->assertJsonPath('data.checkout_url', 'https://rc-epay.esewa.com.np/api/epay/main/v2/form')
        ->assertJsonPath('data.gateway_data.total_amount', '5001.25')
        ->assertJsonPath('data.gateway_data.signed_field_names', 'total_amount,transaction_uuid,product_code')
        ->assertJsonPath('data.gateway_data.success_url', route('payments.esewa.success'))
        ->assertJsonPath('data.gateway_data.failure_url', route('payments.esewa.failure'))
        ->assertDontSee('test-esewa-secret');
    $fields = $response->json('data.gateway_data');

    expect($fields['signature'])->toBe(base64_encode(hash_hmac('sha256', "total_amount=5001.25,transaction_uuid={$fields['transaction_uuid']},product_code=EPAYTEST", 'test-esewa-secret', true)));
    expect($fields['transaction_uuid'])->not->toBe($payment->gateway_reference)->toMatch('/^[a-zA-Z0-9-]+$/');
    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'total_minor' => 500125, 'tip_minor' => 100]);
    $this->assertDatabaseCount('payments', 2);
    Http::assertNothingSent();
});

it('rejects a callback without signed data with 422', function (): void {
    $this->getJson(route('payments.esewa.success'))->assertUnprocessable()->assertJsonValidationErrors('data');
    Http::assertNothingSent();
});

it('confirms and rewards a booking once after a verified callback and redirects to the frontend', function (): void {
    [$user, $booking, $payment] = createEsewaCheckout();
    RewardConfiguration::query()->create(['points_per_rupee' => 1, 'basis' => 'total', 'is_active' => true]);
    fakeEsewaStatus($payment);
    $redirect = "https://spa.example/booking-history?success=true&booking_id={$booking->id}&payment_id={$payment->id}";

    $this->getJson(esewaSuccessCallback($payment))->assertRedirect($redirect);
    $this->getJson(esewaSuccessCallback($payment))->assertRedirect($redirect);

    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'confirmed', 'reward_points_earned' => 5001]);
    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'succeeded']);
    $this->assertDatabaseHas('users', ['id' => $user->id, 'reward_points' => 5001]);
    expect($payment->refresh()->paid_at)->not->toBeNull();
    expect($payment->gateway_payload['verification']['ref_id'])->toBe('0001TS9');
    expect($booking->statusHistory()->where('new_status', 'confirmed')->count())->toBe(1);
    expect($booking->rewardTransactions()->count())->toBe(1);
    expect($user->notifications()->where('title', 'Payment confirmed')->count())->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request['product_code'] === 'EPAYTEST'
        && $request['transaction_uuid'] === $payment->gateway_reference
        && $request['total_amount'] === '5001.25');
});

it('rejects malformed callbacks with 400 without confirming a booking', function (string $data): void {
    [$user, $booking, $payment] = createEsewaCheckout();

    $this->getJson(route('payments.esewa.success', ['data' => $data]))
        ->assertStatus(400)->assertJsonPath('code', 'PAYMENT_GATEWAY_ERROR');

    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending', 'paid_at' => null]);
    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'awaiting_payment']);
    Http::assertNothingSent();
})->with(['invalid base64' => '!!!', 'invalid json' => base64_encode('{'), 'missing signed fields' => base64_encode('{}')]);

it('rejects callbacks that do not match the payment', function (array $overrides, int $status): void {
    [$user, $booking, $payment] = createEsewaCheckout();

    $this->getJson(esewaSuccessCallback($payment, $overrides))->assertStatus($status);

    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending', 'paid_at' => null]);
    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'awaiting_payment']);
    Http::assertNothingSent();
})->with([
    'wrong amount' => [['total_amount' => '1.00'], 400],
    'unknown reference' => [['transaction_uuid' => 'another-payment'], 404],
    'wrong merchant' => [['product_code' => 'ANOTHER-MERCHANT'], 400],
    'wrong status' => [['status' => 'PENDING'], 400],
    'incomplete signature coverage' => [['signed_field_names' => 'transaction_uuid'], 400],
]);

it('rejects a tampered signature with 400 before contacting eSewa', function (): void {
    [$user, $booking, $payment] = createEsewaCheckout();
    $payload = json_decode(base64_decode(esewaCallbackData($payment)), true);
    $payload['total_amount'] = '1.00';

    $this->getJson(route('payments.esewa.success', ['data' => base64_encode(json_encode($payload))]))->assertStatus(400);

    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'awaiting_payment']);
    Http::assertNothingSent();
});

it('rejects mismatched server verification details with 400', function (array $overrides): void {
    [$user, $booking, $payment] = createEsewaCheckout();
    fakeEsewaStatus($payment, $overrides);

    $this->getJson(esewaSuccessCallback($payment))->assertStatus(400)->assertJsonPath('code', 'PAYMENT_GATEWAY_ERROR');

    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending', 'paid_at' => null]);
    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'awaiting_payment']);
    Http::assertSentCount(1);
})->with([
    'amount mismatch' => [['total_amount' => 1]],
    'reference mismatch' => [['transaction_uuid' => 'another-payment']],
    'merchant mismatch' => [['product_code' => 'ANOTHER-MERCHANT']],
    'receipt mismatch' => [['ref_id' => 'another-receipt']],
    'missing receipt' => [['ref_id' => null]],
    'unknown status' => [['status' => 'UNKNOWN']],
    'refunded payment' => [['status' => 'FULL_REFUND']],
]);

it('leaves the booking unconfirmed when eSewa has not completed payment', function (string $status): void {
    [$user, $booking, $payment] = createEsewaCheckout();
    fakeEsewaStatus($payment, ['status' => $status, 'ref_id' => null]);

    $this->getJson(esewaSuccessCallback($payment))->assertStatus(400)->assertJsonPath('code', 'PAYMENT_GATEWAY_ERROR');

    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending', 'paid_at' => null]);
    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'awaiting_payment']);
    Http::assertSentCount(1);
})->with(['PENDING', 'AMBIGUOUS', 'NOT_FOUND', 'CANCELED']);

it('leaves a payment pending and retryable on verification failures', function (string $failure): void {
    [$user, $booking, $payment] = createEsewaCheckout();
    $response = match ($failure) {
        'connection' => Http::failedConnection(),
        'server' => Http::response([], 503),
        'malformed' => Http::response('invalid json'),
        'unavailable' => Http::response(['code' => 0, 'error_message' => 'Service is currently unavailable']),
    };
    Http::fake(['rc.esewa.com.np/api/epay/transaction/status/*' => $response]);

    $this->getJson(esewaSuccessCallback($payment))->assertStatus(400)->assertJsonPath('code', 'PAYMENT_GATEWAY_ERROR');

    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending', 'paid_at' => null]);
    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'awaiting_payment']);
})->with(['connection', 'server', 'malformed', 'unavailable']);

it('completes payment when the signed callback is retried after a provider outage', function (): void {
    [$user, $booking, $payment] = createEsewaCheckout();
    Http::fake([
        'rc.esewa.com.np/api/epay/transaction/status/*' => Http::sequence()->push([], 503)->push([
            'transaction_uuid' => $payment->gateway_reference, 'product_code' => 'EPAYTEST',
            'total_amount' => 5001.25, 'status' => 'COMPLETE', 'ref_id' => '0001TS9',
        ]),
    ]);

    $this->getJson(esewaSuccessCallback($payment))->assertStatus(400);
    $this->getJson(esewaSuccessCallback($payment))->assertRedirect();

    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'confirmed']);
    Http::assertSentCount(2);
});

it('returns 403 when another customer attempts checkout', function (): void {
    [$user, $booking, $payment] = createEsewaCheckout();

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->id}/checkout", ['quote_id' => $booking->quote->id, 'gateway' => 'esewa'])
        ->assertForbidden();

    $this->assertDatabaseCount('payments', 1);
    Http::assertNothingSent();
});

it('returns 401 for checkout without authentication', function (): void {
    $this->postJson('/api/v1/bookings/01K00000000000000000000000/checkout', [
        'quote_id' => '01K00000000000000000000001', 'gateway' => 'esewa',
    ])->assertUnauthorized();
});

it('records late payment without reopening a cancelled booking', function (): void {
    [$user, $booking, $payment] = createEsewaCheckout();
    $booking->forceFill(['status' => BookingStatus::Cancelled])->save();
    fakeEsewaStatus($payment);

    $this->getJson(esewaSuccessCallback($payment))->assertRedirect();

    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'succeeded']);
    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'cancelled']);
    expect($booking->rewardTransactions()->count())->toBe(0);
    Http::assertSentCount(1);
});

it('keeps refunded payments terminal when an old success callback is replayed', function (): void {
    [$user, $booking, $payment] = createEsewaCheckout();
    $payment->forceFill(['status' => PaymentStatus::Refunded])->save();
    $booking->forceFill(['status' => BookingStatus::Refunded])->save();
    fakeEsewaStatus($payment);

    $this->getJson(esewaSuccessCallback($payment))->assertRedirect("https://spa.example/booking-history?success=false&booking_id={$booking->id}&payment_id={$payment->id}");

    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'refunded']);
    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'refunded']);
    Http::assertSentCount(1);
});

it('creates a new payment when the quote changes without accepting an old lower amount', function (): void {
    [$user, $booking, $payment] = createEsewaCheckout();
    $quote = $this->postJson("/api/v1/bookings/{$booking->id}/checkout/quote", ['tip_minor' => 200])
        ->assertOk()->json('data.quote_id');
    $this->postJson("/api/v1/bookings/{$booking->id}/checkout", ['quote_id' => $quote, 'gateway' => 'esewa'])
        ->assertOk()->assertJsonPath('data.gateway_data.total_amount', '5002.25');
    fakeEsewaStatus($payment);

    $this->getJson(esewaSuccessCallback($payment))->assertRedirect();

    $this->assertDatabaseCount('payments', 2);
    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'succeeded']);
    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'total_minor' => 500225, 'status' => 'awaiting_payment']);
    Http::assertSentCount(1);
});

it('shows other gateways as unavailable and rejects their checkout without creating payments', function (string $gateway): void {
    [$user, $booking, $payment] = createEsewaCheckout();
    $this->getJson("/api/v1/bookings/{$booking->id}/checkout")->assertOk()
        ->assertJsonPath('data.payment_providers.0.available', true)
        ->assertJsonPath('data.payment_providers.1.available', false)
        ->assertJsonPath('data.payment_providers.2.available', false);

    $this->postJson("/api/v1/bookings/{$booking->id}/checkout", ['quote_id' => $booking->quote->id, 'gateway' => $gateway])
        ->assertStatus(400)->assertJsonPath('code', 'PAYMENT_GATEWAY_ERROR');

    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
})->with(['khalti', 'mypay']);

it('disables checkout when eSewa credentials are missing', function (): void {
    [$user, $booking, $payment] = createEsewaCheckout();
    config(['services.esewa.secret' => null]);

    $this->getJson("/api/v1/bookings/{$booking->id}/checkout")->assertOk()->assertJsonPath('data.payment_providers.0.available', false);
    $this->postJson("/api/v1/bookings/{$booking->id}/checkout", ['quote_id' => $booking->quote->id, 'gateway' => 'esewa'])
        ->assertStatus(400)->assertJsonPath('code', 'PAYMENT_GATEWAY_ERROR');
    $this->assertDatabaseCount('payments', 1);
});

it('starts a fresh gateway session after the previous quote expires', function (): void {
    $this->freezeTime();
    [$user, $booking, $payment] = createEsewaCheckout();
    $this->travel(16)->minutes();
    $quote = $this->postJson("/api/v1/bookings/{$booking->id}/checkout/quote", ['tip_minor' => 100])->assertOk()->json('data.quote_id');

    $response = $this->postJson("/api/v1/bookings/{$booking->id}/checkout", [
        'quote_id' => $quote, 'gateway' => 'esewa',
    ])->assertOk()->assertJsonPath('data.gateway_data.total_amount', '5001.25');

    expect($response->json('data.gateway_data.transaction_uuid'))->not->toBe($payment->gateway_reference);
    $this->assertDatabaseCount('payments', 2);
});

it('verifies post-service tips through the same eSewa callback', function (string $remoteStatus, string $tipStatus): void {
    [$user, $booking, $bookingPayment] = createEsewaCheckout();
    $booking->forceFill(['status' => BookingStatus::Completed, 'completed_at' => now()])->save();
    $first = $this->postJson("/api/v1/bookings/{$booking->id}/tip/checkout", ['amount_minor' => 10000, 'gateway' => 'esewa'])
        ->assertOk()->assertJsonPath('data.gateway_data.total_amount', '100.00')->json('data.payment_id');
    $payment = Payment::query()->findOrFail($first);
    fakeEsewaStatus($payment, ['total_amount' => 100, 'status' => $remoteStatus]);
    $callback = esewaSuccessCallback($payment, ['total_amount' => '100.00']);

    $response = $this->getJson($callback);
    $response->assertStatus($remoteStatus === 'COMPLETE' ? 302 : 400);
    $this->getJson($callback)->assertStatus($remoteStatus === 'COMPLETE' ? 302 : 400);

    $this->assertDatabaseCount('tips', 1);
    $this->assertDatabaseHas('tips', ['payment_id' => $first, 'status' => $tipStatus]);
    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'completed']);
    expect($user->notifications()->where('title', 'Tip received')->count())->toBe($tipStatus === 'paid' ? 1 : 0);
    Http::assertSentCount(2);
})->with([['COMPLETE', 'paid'], ['CANCELED', 'pending']]);

it('rejects nonpositive amounts and unsupported currencies without creating a new payment', function (int $amountMinor, string $currency): void {
    [$user, $booking, $payment] = createEsewaCheckout();
    $booking->forceFill(['currency' => $currency])->save();
    $quote = $booking->quote;
    $quote->forceFill(['total_minor' => $amountMinor])->save();

    $this->postJson("/api/v1/bookings/{$booking->id}/checkout", ['quote_id' => $quote->id, 'gateway' => 'esewa'])
        ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');

    $this->assertDatabaseCount('payments', 1);
})->with([[0, 'NPR'], [500125, 'USD']]);

it('rejects expired appointments with no new payment', function (): void {
    $this->freezeTime();
    [$user, $booking, $payment] = createEsewaCheckout();
    $booking->forceFill(['starts_at' => now()->subMinute()])->save();

    $this->postJson("/api/v1/bookings/{$booking->id}/checkout", ['quote_id' => $booking->quote->id, 'gateway' => 'esewa'])
        ->assertConflict()->assertJsonPath('code', 'BOOKING_STATUS_TRANSITION_INVALID');

    $this->assertDatabaseCount('payments', 1);
});

it('awards booking rewards once when callbacks arrive for separate payment attempts', function (): void {
    [$user, $booking, $first] = createEsewaCheckout();
    RewardConfiguration::query()->create(['points_per_rupee' => 1, 'basis' => 'total', 'is_active' => true]);
    $response = $this->postJson("/api/v1/bookings/{$booking->id}/checkout", [
        'quote_id' => $booking->quote->id, 'gateway' => 'esewa',
    ])->assertOk();
    $second = Payment::query()->findOrFail($response->json('data.payment_id'));
    Http::fake([
        'rc.esewa.com.np/api/epay/transaction/status/*' => fn (Request $request) => Http::response([
            'transaction_uuid' => $request['transaction_uuid'], 'product_code' => 'EPAYTEST',
            'total_amount' => 5001.25, 'status' => 'COMPLETE', 'ref_id' => '0001TS9',
        ]),
    ]);

    $this->getJson(esewaSuccessCallback($first))->assertRedirect();
    $this->getJson(esewaSuccessCallback($second))->assertRedirect();

    $this->assertDatabaseHas('users', ['id' => $user->id, 'reward_points' => 5001]);
    expect($booking->statusHistory()->where('new_status', 'confirmed')->count())->toBe(1);
    expect($user->notifications()->where('title', 'Payment confirmed')->count())->toBe(1);
    Http::assertSentCount(2);
});

it('uses HTTP callbacks with a mobile frontend and redirects cancelled payments without confirming them', function (): void {
    config(['services.frontend.url' => 'spaapp://booking-history']);
    [$user, $booking, $payment] = createEsewaCheckout();
    expect($payment->gateway_payload['success_url'])->toBe(route('payments.esewa.success'));
    expect($payment->gateway_payload['failure_url'])->toBe(route('payments.esewa.failure'));

    $this->get($payment->gateway_payload['failure_url'])->assertRedirect('spaapp://booking-history?success=false');

    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending', 'paid_at' => null]);
    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'awaiting_payment']);
    expect($user->notifications()->where('title', 'Payment confirmed')->count())->toBe(0);
    Http::assertNothingSent();
});

it('preserves the frontend query parameters on payment failure', function (): void {
    config(['services.frontend.url' => 'spaapp://booking-history?source=checkout']);

    $this->get(route('payments.esewa.failure'))->assertRedirect('spaapp://booking-history?source=checkout&success=false');
    Http::assertNothingSent();
});

it('redirects a verified successful payment back to the mobile app', function (): void {
    config(['services.frontend.url' => 'spaapp://booking-history']);
    [$user, $booking, $payment] = createEsewaCheckout();
    fakeEsewaStatus($payment);

    $this->getJson(esewaSuccessCallback($payment))
        ->assertRedirect("spaapp://booking-history?success=true&booking_id={$booking->id}&payment_id={$payment->id}");

    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'succeeded']);
    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'confirmed']);
    Http::assertSentCount(1);
});
