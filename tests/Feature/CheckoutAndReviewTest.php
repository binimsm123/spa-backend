<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\CheckoutQuote;
use App\Models\Offer;
use App\Models\Payment;
use App\Models\RewardConfiguration;

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

it('starts a payment and confirms the booking via an idempotent webhook', function (): void {
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
            'gateway' => 'khalti',
        ])->assertOk()->json('data');

    expect($booking->refresh()->status)->toBe(BookingStatus::AwaitingPayment);

    $secret = config('spa.payments.webhook_secret');
    $payload = ['gateway_reference' => Payment::query()->findOrFail($payment['payment_id'])->gateway_reference, 'status' => 'succeeded'];
    $signature = hash_hmac('sha256', json_encode($payload), $secret);

    // Deliver the webhook twice: it must be idempotent.
    foreach ([1, 2] as $i) {
        $this->postJson('/api/v1/webhooks/payments/khalti', $payload, ['X-Webhook-Signature' => $signature])
            ->assertOk();
    }

    expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed)
        ->and(Payment::query()->whereKey($payment['payment_id'])->first()->status->value)->toBe('succeeded');

    // Reward points awarded once.
    expect($booking->user->refresh()->reward_points)->toBeGreaterThan(0)
        ->and($booking->refresh()->reward_points_earned)->toBeGreaterThan(0);
});

it('rejects webhooks with invalid signatures', function (): void {
    $this->postJson('/api/v1/webhooks/payments/esewa', [
        'gateway_reference' => 'unknown',
        'status' => 'succeeded',
    ], ['X-Webhook-Signature' => 'bad-signature'])->assertStatus(400);
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
            'gateway' => 'mypay',
        ])->assertOk()->assertJsonPath('success', true);
});

it('uses the business address in checkout and booking details', function (): void {
    [$user] = actingAsCustomer();
    $booking = createPaidBooking($user);
    $booking->business->update(['address' => 'Street 12, Patan']);

    $this->getJson("/api/v1/bookings/{$booking->getKey()}/checkout")->assertOk()
        ->assertJsonPath('data.booking.location', 'Street 12, Patan');
    $response = $this->getJson("/api/v1/bookings/{$booking->getKey()}")->assertOk()
        ->assertJsonPath('data.business_id', $booking->business_id)
        ->assertJsonPath('data.business.address', 'Street 12, Patan');
    expect($response->json('data'))->not->toHaveKeys(['business_location_id', 'business_location']);
});
