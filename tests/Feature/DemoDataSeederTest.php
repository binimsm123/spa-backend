<?php

use App\Models\Booking;
use App\Models\Business;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\Auth;

it('seeds a connected spa dataset and can rerun without duplicating it', function (): void {
    $this->freezeTime();
    $this->seed(DemoDataSeeder::class);
    $this->seed(DemoDataSeeder::class);

    $this->assertDatabaseCount('users', 27);
    $this->assertDatabaseCount('businesses', 6);
    $this->assertDatabaseCount('business_users', 18);
    $this->assertDatabaseCount('categories', 24);
    $this->assertDatabaseCount('services', 48);
    $this->assertDatabaseCount('service_prices', 54);
    $this->assertDatabaseCount('business_hours', 42);
    $this->assertDatabaseCount('business_closures', 6);
    $this->assertDatabaseCount('offers', 6);
    $this->assertDatabaseCount('booking_requests', 24);
    $this->assertDatabaseCount('booking_request_times', 24);
    $this->assertDatabaseCount('bookings', 20);
    $this->assertDatabaseCount('booking_items', 20);
    $this->assertDatabaseCount('booking_status_history', 20);
    $this->assertDatabaseCount('payments', 12);
    $this->assertDatabaseCount('reviews', 8);
    $this->assertDatabaseCount('notifications', 20);
    $this->assertDatabaseCount('reward_configurations', 1);

    $business = Business::query()->where('slug', 'serenity-spa-thamel')->firstOrFail();
    expect($business->rating_average)->toBe(4.5)
        ->and($business->rating_count)->toBe(2);
    foreach (Booking::with(['items.service', 'assignedStaff', 'bookingRequest'])->get() as $booking) {
        expect($booking->items->first()->service->business_id)->toBe($booking->business_id)
            ->and($booking->assignedStaff->belongsToBusiness($booking->business))->toBeTrue()
            ->and($booking->total_minor)->toBe($booking->items->sum('unit_price_minor'));
    }

    $login = $this->postJson('/api/v1/auth/login', ['mobile_number' => '9820000001', 'password' => DemoDataSeeder::PASSWORD])
        ->assertOk()->assertJsonPath('data.user.mobile_number', '9820000001');
    $this->withToken($login->json('data.access_token'))->getJson('/api/v1/businesses?q=Serenity')
        ->assertOk()->assertJsonCount(2, 'data.items');
    $this->withToken($login->json('data.access_token'))->getJson('/api/v1/home')
        ->assertOk()->assertJsonPath('data.user.display_name', 'Aarav Sharma');

    $ownerLogin = $this->postJson('/api/v1/auth/login', ['mobile_number' => '9811111111', 'password' => DemoDataSeeder::PASSWORD])->assertOk();
    Auth::forgetGuards();
    $this->withToken($ownerLogin->json('data.access_token'))->getJson('/api/v1/business/my')
        ->assertOk()->assertJsonCount(2, 'data.items');
});
