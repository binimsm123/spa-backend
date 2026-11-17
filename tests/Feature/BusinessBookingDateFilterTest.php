<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\User;

function createBusinessBookingOnDate(Business $business, User $customer, string $date, string $status = 'confirmed'): Booking
{
    return Booking::query()->create([
        'business_id' => $business->id,
        'user_id' => $customer->id,
        'appointment_date' => $date,
        'starts_at' => $date.' 10:00:00',
        'ends_at' => $date.' 11:00:00',
        'timezone' => $business->timezone,
        'status' => $status,
    ]);
}

it('filters business bookings by inclusive appointment dates while preserving status and business isolation', function (array $filters, array $dates): void {
    [$business] = createBusinessWithService();
    [$otherBusiness] = createBusinessWithService();
    $owner = bookingApprover($business);
    $customer = User::factory()->create();
    foreach (['2026-11-21', '2026-11-17', '2026-11-16', '2026-11-20', '2026-11-18'] as $date) {
        createBusinessBookingOnDate($business, $customer, $date);
    }
    createBusinessBookingOnDate($business, $customer, '2026-11-18', 'cancelled');
    createBusinessBookingOnDate($otherBusiness, $customer, '2026-11-18');

    $this->actingAs($owner, 'sanctum')
        ->getJson('/api/v1/business/'.$business->id.'/bookings?'.http_build_query(['status' => 'confirmed', ...$filters]))
        ->assertOk()
        ->assertJsonPath('data.items', fn (array $items): bool => array_column($items, 'appointment_date') === $dates)
        ->assertJsonPath('data.pagination.total', count($dates));
})->with([
    'no date filter' => [[], ['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-20', '2026-11-21']],
    'upcoming from a date' => [['from' => '2026-11-17'], ['2026-11-17', '2026-11-18', '2026-11-20', '2026-11-21']],
    'through a date' => [['to' => '2026-11-18'], ['2026-11-16', '2026-11-17', '2026-11-18']],
    'bounded range' => [['from' => '2026-11-17', 'to' => '2026-11-20'], ['2026-11-17', '2026-11-18', '2026-11-20']],
    'same day range' => [['from' => '2026-11-18', 'to' => '2026-11-18'], ['2026-11-18']],
    'exact date' => [['date' => '2026-11-18'], ['2026-11-18']],
    'exact date within range' => [['date' => '2026-11-18', 'from' => '2026-11-17', 'to' => '2026-11-20'], ['2026-11-18']],
    'no matching dates' => [['from' => '2026-11-22'], []],
]);

it('paginates upcoming business bookings in appointment order', function (): void {
    [$business] = createBusinessWithService();
    $owner = bookingApprover($business);
    $customer = User::factory()->create();
    foreach (['2026-11-19', '2026-11-17', '2026-11-18', '2026-11-16'] as $date) {
        createBusinessBookingOnDate($business, $customer, $date);
    }

    $this->actingAs($owner, 'sanctum')
        ->getJson('/api/v1/business/'.$business->id.'/bookings?status=confirmed&from=2026-11-17&page=2&per_page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.appointment_date', '2026-11-19')
        ->assertJsonPath('data.pagination', ['page' => 2, 'per_page' => 2, 'total' => 3, 'total_pages' => 2, 'has_more' => false]);
});

it('returns 422 for malformed or reversed business booking date ranges', function (array $filters, string $field): void {
    [$business] = createBusinessWithService();

    $this->actingAs(bookingApprover($business), 'sanctum')
        ->getJson('/api/v1/business/'.$business->id.'/bookings?'.http_build_query($filters))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors($field);
})->with([
    'malformed start date' => [['from' => 'not-a-date'], 'from'],
    'malformed end date' => [['to' => '17-11-2026'], 'to'],
    'impossible date' => [['from' => '2026-02-30'], 'from'],
    'reversed range' => [['from' => '2026-11-20', 'to' => '2026-11-17'], 'to'],
]);

it('returns 403 when an outsider filters business bookings', function (): void {
    [$business] = createBusinessWithService();

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson('/api/v1/business/'.$business->id.'/bookings?from=2026-11-17')
        ->assertForbidden();
});

it('returns 401 when filtering business bookings without authentication', function (): void {
    [$business] = createBusinessWithService();

    $this->getJson('/api/v1/business/'.$business->id.'/bookings?from=2026-11-17')
        ->assertUnauthorized();
});
