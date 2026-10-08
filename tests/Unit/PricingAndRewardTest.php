<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\Offer;
use App\Models\OfferRedemption;
use App\Models\RewardConfiguration;
use App\Services\OfferService;
use App\Services\RewardService;
use App\Support\ApiException;

it('calculates percentage discounts with caps', function (): void {
    $offer = new Offer([
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'max_discount_minor' => 5000,
    ]);

    $service = app(OfferService::class);

    expect($service->calculateDiscount($offer, 100000))->toBe(5000)
        ->and($service->calculateDiscount($offer->replicate()->fill(['max_discount_minor' => null]), 100000))->toBe(10000)
        ->and($service->calculateDiscount($offer, 30000))->toBe(3000);
});

it('calculates fixed discounts bounded by subtotal', function (): void {
    $offer = new Offer(['discount_type' => 'fixed', 'discount_value' => 8000]);
    $service = app(OfferService::class);

    expect($service->calculateDiscount($offer, 5000))->toBe(5000)
        ->and($service->calculateDiscount($offer, 50000))->toBe(8000);
});

it('awards reward points and keeps the ledger consistent with the cached balance', function (): void {
    RewardConfiguration::query()->create(['points_per_rupee' => 1, 'basis' => 'subtotal', 'is_active' => true]);

    [$user] = registerCustomer();

    $booking = Booking::query()->create([
        'user_id' => $user->getKey(),
        'business_id' => Business::query()->create(['name' => 'B', 'slug' => 'b-'.Str::random(5)])->getKey(),
        'appointment_date' => now()->toDateString(),
        'starts_at' => now(),
        'ends_at' => now()->addHour(),
        'status' => 'awaiting_payment',
        'subtotal_minor' => 100000, // NPR 1000
        'total_minor' => 100000,
        'currency' => 'NPR',
    ]);

    app(RewardService::class)->awardForBooking($booking->refresh());

    $user->refresh();

    expect($user->reward_points)->toBe(1000);

    $ledgerTotal = (int) $user->rewardTransactions()->sum('points');

    expect($ledgerTotal)->toBe($user->reward_points);
});

it('rejects reward redemption when the balance is insufficient', function (): void {
    [$user] = registerCustomer();

    $offer = Offer::query()->create([
        'code' => 'PTS'.Str::upper(Str::random(5)),
        'title' => 'Points offer',
        'discount_type' => 'fixed',
        'discount_value' => 1000,
        'requires_reward_points' => true,
        'reward_points_cost' => 500,
        'starts_at' => now()->subDay(),
        'expires_at' => now()->addDay(),
        'status' => 'active',
    ]);

    $redemption = OfferRedemption::query()->create([
        'offer_id' => $offer->getKey(),
        'user_id' => $user->getKey(),
        'idempotency_key' => (string) Str::ulid(),
    ]);

    app(RewardService::class)->spendPoints($user, 500, $redemption);
})->throws(ApiException::class);
