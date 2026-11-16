<?php

use App\Models\Service;
use App\Models\User;
use App\Services\AvailabilityService;
use Illuminate\Support\Carbon;

it('loads availability data once per batch rather than once per date', function (): void {
    $this->travelTo(Carbon::parse('2026-11-14 08:00', 'UTC'));
    config(['spa.booking.availability_cache_seconds' => 0]);
    [$business, $service, $price] = createBookableBusinessWithService();
    automaticBooking($business, $service->staffMemberships()->firstOrFail()->user_id, '09:00', '10:00');
    $this->expectsDatabaseQueryCount(12);

    $result = app(AvailabilityService::class)->availableDatesFor($business, $service, 1, $price->id);

    expect($result['dates']->all())->toBe(['2026-11-14', '2026-11-15', '2026-11-16', '2026-11-17', '2026-11-18']);
    expect($result['availability']->first()['slots'][0])->toBe(['starts_at' => '10:00', 'ends_at' => '11:00']);
});

it('stops searching immediately when no qualified staff can provide the service', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 08:00', 'UTC'));
    config(['spa.booking.availability_cache_seconds' => 0]);
    [$business, $service] = createBookableBusinessWithService();
    $service->staffMemberships()->detach();
    $this->expectsDatabaseQueryCount(10);

    $result = app(AvailabilityService::class)->availableDatesFor($business, $service);

    expect($result['dates'])->toBeEmpty();
});

it('stops after five future dates without searching the remaining horizon for a backup date', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 17:00', 'UTC'));
    config(['spa.booking.availability_cache_seconds' => 0]);
    [$business, $service] = createBookableBusinessWithService();
    $service->staffMemberships()->firstOrFail()->leaves()->create(['starts_on' => '2026-11-09', 'ends_on' => '2027-02-28']);
    $this->expectsDatabaseQueryCount(10);

    $result = app(AvailabilityService::class)->availableDatesFor($business, $service);

    expect($result['dates']->all())->toBe(['2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08']);
});

it('loads subsequent date batches when staff are on leave throughout the first batch', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 08:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $service->staffMemberships()->firstOrFail()->leaves()->create(['starts_on' => '2026-11-03', 'ends_on' => '2026-11-16']);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}")
        ->assertOk()->assertJsonPath('data.dates', ['2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21']);
});

it('respects bookings at another business using that business timezone in a batch', function (): void {
    [$business, $service, $membership] = automaticAvailabilityFixture();
    $this->travelTo(Carbon::parse('2026-11-14 08:00', 'UTC'));
    [$other] = createBusinessWithService();
    $other->update(['timezone' => 'Asia/Kathmandu']);
    automaticBooking($other, $membership->user_id, '15:45', '16:45');

    $this->getJson("/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}")
        ->assertOk()->assertJsonPath('data.availability.0.date', '2026-11-14')
        ->assertJsonPath('data.availability.0.slots.0', ['starts_at' => '11:00', 'ends_at' => '12:00']);
});

it('accounts for unassigned bookings of other services without discarding their qualified staff', function (): void {
    $this->travelTo(Carbon::parse('2026-11-14 08:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $otherService = Service::query()->create(['business_id' => $business->id, 'name' => 'Other massage', 'slug' => 'other-massage']);
    $first = $service->staffMemberships()->firstOrFail();
    $first->services()->attach($otherService);
    $second = addBookingStaff($business, $otherService);
    $booking = automaticBooking($business, null, '09:00', '10:00');
    $booking->items()->create([
        'service_id' => $otherService->id, 'service_name_snapshot' => $otherService->name,
        'duration_minutes_snapshot' => 60, 'unit_price_minor' => 500000, 'required_staff_count_snapshot' => 1,
    ]);
    $url = "/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}";
    $this->actingAs(User::factory()->create(), 'sanctum');

    $this->getJson($url)->assertOk()
        ->assertJsonPath('data.availability.0.slots.0', ['starts_at' => '09:00', 'ends_at' => '10:00']);
    $second->update(['is_active' => false]);
    config(['spa.booking.availability_cache_seconds' => 0]);
    $this->getJson($url)->assertOk()
        ->assertJsonPath('data.availability.0.slots.0', ['starts_at' => '10:00', 'ends_at' => '11:00']);
});
