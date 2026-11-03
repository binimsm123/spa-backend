<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\CheckoutQuote;
use App\Models\Offer;
use App\Models\Payment;
use App\Models\RewardConfiguration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config([
        'services.frontend.url' => 'https://spa.example/booking-history',
        'services.esewa.product_code' => 'EPAYTEST',
        'services.esewa.secret' => 'test-esewa-secret',
    ]);
});

function createPaidBooking($user): Booking
{
    [$business, $service] = createBusinessWithService();

    $booking = Booking::query()->create([
        'user_id' => $user->getKey(),
        'business_id' => $business->getKey(),
        'appointment_date' => now()->addDay()->toDateString(),
        'starts_at' => now()->addDay()->setTimeFromTimeString('10:00'),
        'ends_at' => now()->addDay()->setTimeFromTimeString('11:00'),
        'status' => BookingStatus::AwaitingPayment->value,
        'subtotal_minor' => 500000,
        'total_minor' => 500000,
        'currency' => 'NPR',
    ]);

    $booking->items()->create([
        'service_id' => $service->getKey(),
        'service_name_snapshot' => $service->name,
        'duration_minutes_snapshot' => $service->currentPrice->durationMinutes(),
        'max_people_snapshot' => $service->max_people,
        'unit_price_minor' => 500000,
        'currency' => 'NPR',
        'quantity' => 1,
    ]);

    return $booking;
}

function completeBooking(Booking $booking): Booking
{
    $booking->forceFill(['status' => BookingStatus::Confirmed->value, 'confirmed_at' => now()])->save();
    $booking->forceFill(['status' => BookingStatus::Completed->value, 'completed_at' => now()])->save();

    return $booking->refresh();
}

it('builds a checkout quote with promo discount and recalculates totals server-side', function (): void {
    [$user] = actingAsCustomer();
    $booking = createPaidBooking($user);

    Offer::query()->create([
        'code' => 'SAVE10',
        'title' => 'Save 10',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'starts_at' => now()->subDay(),
        'expires_at' => now()->addDay(),
        'status' => 'active',
        'per_user_limit' => 5,
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->getKey()}/checkout/quote", ['promo_code' => 'save10'])
        ->assertOk()
        ->assertJsonPath('data.pricing.total', 4500)
        ->assertJsonPath('data.pricing.discount', 500);
});

it('creates a default quote and payment without a quote id', function (): void {
    [$user] = actingAsCustomer();
    $booking = createPaidBooking($user);
    $booking->forceFill(['tax_minor' => 10000, 'tip_minor' => 20000, 'discount_minor' => 50000])->save();
    $this->freezeTime();

    $payment = $this->postJson("/api/v1/bookings/{$booking->id}/checkout", [
        'gateway' => 'esewa',
    ])->assertOk()
        ->assertJsonPath('data.amount', 5100)
        ->assertJsonPath('data.gateway_data.total_amount', '5100.00')
        ->json('data.payment_id');

    $this->assertDatabaseHas('checkout_quotes', [
        'booking_id' => $booking->id, 'subtotal_minor' => 500000, 'tax_minor' => 10000,
        'tip_minor' => 0, 'discount_minor' => 0, 'offer_id' => null,
        'total_minor' => 510000, 'currency' => 'NPR',
    ]);
    expect($booking->quote->expires_at->isFuture())->toBeTrue();
    $this->assertDatabaseHas('payments', ['id' => $payment, 'booking_id' => $booking->id, 'amount_minor' => 510000]);
    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'tip_minor' => 0, 'discount_minor' => 0, 'total_minor' => 510000]);
});

it('does not reuse a preview quote when quote id is omitted', function (): void {
    [$user] = actingAsCustomer();
    $booking = createPaidBooking($user);
    $this->postJson("/api/v1/bookings/{$booking->id}/checkout/quote", ['tip_minor' => 20000])
        ->assertOk()->assertJsonPath('data.pricing.total', 5200);

    $this->postJson("/api/v1/bookings/{$booking->id}/checkout", ['gateway' => 'esewa'])
        ->assertOk()->assertJsonPath('data.amount', 5000);

    $this->assertDatabaseCount('checkout_quotes', 2);
    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'tip_minor' => 0, 'total_minor' => 500000]);
});

it('returns 422 for an invalid supplied quote id without creating a payment', function ($quoteId): void {
    [$user] = actingAsCustomer();
    $booking = createPaidBooking($user);

    $this->postJson("/api/v1/bookings/{$booking->id}/checkout", ['gateway' => 'esewa', 'quote_id' => $quoteId])
        ->assertUnprocessable()->assertJsonValidationErrors('quote_id');

    $this->assertDatabaseCount('checkout_quotes', 0);
    $this->assertDatabaseCount('payments', 0);
})->with(['null' => [null], 'empty' => [''], 'malformed' => ['invalid']]);

it('returns 404 for a missing supplied quote without generating a default', function (): void {
    [$user] = actingAsCustomer();
    $booking = createPaidBooking($user);

    $this->postJson("/api/v1/bookings/{$booking->id}/checkout", [
        'gateway' => 'esewa', 'quote_id' => (string) Str::ulid(),
    ])->assertNotFound();

    $this->assertDatabaseCount('checkout_quotes', 0);
    $this->assertDatabaseCount('payments', 0);
});

it('returns 403 for another customer booking without generating a quote', function (): void {
    [$owner] = actingAsCustomer();
    $booking = createPaidBooking($owner);
    actingAsCustomer();

    $this->postJson("/api/v1/bookings/{$booking->id}/checkout", ['gateway' => 'esewa'])->assertForbidden();

    $this->assertDatabaseCount('checkout_quotes', 0);
    $this->assertDatabaseCount('payments', 0);
});

it('returns 409 for a completed booking without generating a quote', function (): void {
    [$user] = actingAsCustomer();
    $booking = completeBooking(createPaidBooking($user));

    $this->postJson("/api/v1/bookings/{$booking->id}/checkout", ['gateway' => 'esewa'])->assertConflict();

    $this->assertDatabaseCount('checkout_quotes', 0);
    $this->assertDatabaseCount('payments', 0);
});

it('rolls back the default quote when payment initiation fails', function (): void {
    [$user] = actingAsCustomer();
    $booking = createPaidBooking($user);
    config(['services.esewa.secret' => null]);

    $this->postJson("/api/v1/bookings/{$booking->id}/checkout", ['gateway' => 'esewa'])->assertBadRequest();

    $this->assertDatabaseCount('checkout_quotes', 0);
    $this->assertDatabaseCount('payments', 0);
});

it('expires quotes and rejects expired quote checkout', function (): void {
    [$user] = actingAsCustomer();
    $booking = createPaidBooking($user);

    $quote = CheckoutQuote::query()->create([
        'booking_id' => $booking->getKey(),
        'subtotal_minor' => 500000,
        'total_minor' => 500000,
        'currency' => 'NPR',
        'expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->getKey()}/checkout", [
            'quote_id' => $quote->getKey(),
            'gateway' => 'esewa',
        ])->assertStatus(409);
});

it('starts a payment and confirms the booking via an idempotent eSewa callback', function (): void {
    RewardConfiguration::query()->create(['points_per_rupee' => 1, 'basis' => 'subtotal', 'is_active' => true]);

    [$user] = actingAsCustomer();
    $booking = createPaidBooking($user);

    $quote = CheckoutQuote::query()->create([
        'booking_id' => $booking->getKey(),
        'subtotal_minor' => 500000,
        'total_minor' => 500000,
        'currency' => 'NPR',
        'expires_at' => now()->addMinutes(15),
    ]);

    $payment = $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->getKey()}/checkout", [
            'quote_id' => $quote->getKey(),
            'gateway' => 'esewa',
        ])->assertOk()->json('data');

    expect($booking->refresh()->status)->toBe(BookingStatus::AwaitingPayment);

    $reference = Payment::query()->findOrFail($payment['payment_id'])->gateway_reference;
    $payload = [
        'transaction_code' => '0001TS9',
        'status' => 'COMPLETE',
        'total_amount' => '5,000.0',
        'transaction_uuid' => $reference,
        'product_code' => 'EPAYTEST',
        'signed_field_names' => 'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names',
    ];
    $message = implode(',', array_map(fn (string $key): string => $key.'='.$payload[$key], explode(',', $payload['signed_field_names'])));
    $payload['signature'] = base64_encode(hash_hmac('sha256', $message, 'test-esewa-secret', true));
    Http::fake([
        'rc.esewa.com.np/api/epay/transaction/status/*' => Http::response([
            'product_code' => 'EPAYTEST', 'transaction_uuid' => $reference,
            'total_amount' => 5000, 'status' => 'COMPLETE', 'ref_id' => '0001TS9',
        ]),
    ]);

    foreach ([1, 2] as $delivery) {
        $this->getJson(route('payments.esewa.success', ['data' => base64_encode(json_encode($payload))]))
            ->assertRedirect();
    }

    Http::assertSentCount(2);
    expect($booking->statusHistory()->where('new_status', 'confirmed')->count())->toBe(1);
    expect($booking->user->refresh()->reward_points)->toBe(5000);

    expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed)
        ->and(Payment::query()->whereKey($payment['payment_id'])->first()->status->value)->toBe('succeeded');

    // Reward points awarded once.
    expect($booking->user->refresh()->reward_points)->toBeGreaterThan(0)
        ->and($booking->refresh()->reward_points_earned)->toBeGreaterThan(0);
});

it('rejects eSewa callbacks with invalid signatures', function (): void {
    $this->getJson(route('payments.esewa.success', ['data' => base64_encode(json_encode([
        'transaction_code' => 'fake',
        'status' => 'COMPLETE',
        'total_amount' => '5000.00',
        'transaction_uuid' => 'unknown',
        'product_code' => 'EPAYTEST',
        'signed_field_names' => 'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names',
        'signature' => 'bad-signature',
    ]))]))->assertStatus(400)->assertJsonPath('code', 'PAYMENT_GATEWAY_ERROR');
});

it('allows exactly one review per completed booking and blocks non-completed bookings', function (): void {
    [$user] = actingAsCustomer();
    $booking = completeBooking(createPaidBooking($user));

    $payload = ['rating' => 5, 'tag' => 'great', 'comments' => 'Loved it.'];

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->getKey()}/review", $payload)
        ->assertCreated();

    // Second submission is rejected (409).
    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->getKey()}/review", $payload)
        ->assertStatus(409);

    // Business rating aggregate was updated.
    expect($booking->business->refresh()->rating_count)->toBe(1)
        ->and($booking->business->rating_average)->toBe(5.0);
});

it('blocks reviews for bookings that are not completed', function (): void {
    [$user] = actingAsCustomer();
    $booking = createPaidBooking($user);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->getKey()}/review", ['rating' => 4])
        ->assertStatus(409);
});

it('starts a tip checkout only for completed bookings', function (): void {
    [$user] = actingAsCustomer();
    $booking = completeBooking(createPaidBooking($user));

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->getKey()}/tip/checkout", [
            'amount_minor' => 10000,
            'gateway' => 'esewa',
        ])->assertOk()->assertJsonPath('success', true);
});

it('uses the business address in checkout and booking details', function (): void {
    [$user] = actingAsCustomer();
    $booking = createPaidBooking($user);
    $booking->business->locations()->create(['address' => 'Street 12, Patan', 'service_availability' => 'on_site']);

    $this->getJson("/api/v1/bookings/{$booking->getKey()}/checkout")->assertOk()
        ->assertJsonPath('data.booking.location', 'Street 12, Patan');
    $response = $this->getJson("/api/v1/bookings/{$booking->getKey()}")->assertOk()
        ->assertJsonPath('data.business_id', $booking->business_id)
        ->assertJsonPath('data.business.address', 'Street 12, Patan');
    expect($response->json('data'))->not->toHaveKeys(['business_location_id', 'business_location']);
});
