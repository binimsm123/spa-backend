<?php

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\StaffHour;
use App\Models\User;
use Illuminate\Support\Carbon;

/** @return array{User, Business, User, BusinessUser, StaffHour, string} */
function staffBreakFixture(string $role = 'manager', bool $active = true): array
{
    [$business] = createBusinessWithService();
    $actor = User::factory()->create();
    BusinessUser::query()->create(['business_id' => $business->id, 'user_id' => $actor->id, 'role' => $role, 'is_active' => $active]);
    $staff = User::factory()->create();
    $membership = BusinessUser::query()->create(['business_id' => $business->id, 'user_id' => $staff->id, 'role' => 'staff', 'is_active' => true]);
    $hours = StaffHour::factory()->for($membership, 'businessMembership')->create([
        'weekday' => 1, 'breaks' => [['name' => 'Meal', 'starts_at' => '12:00', 'ends_at' => '13:00']],
    ]);

    return [$actor, $business, $staff, $membership, $hours, '/api/v1/business/'.$business->id.'/staff/'.$staff->id.'/breaks'];
}

it('lets owners and managers append breaks for one weekday without replacing hours or existing breaks', function (string $role): void {
    [$actor, , , $membership, $hours, $path] = staffBreakFixture($role);
    $otherDay = StaffHour::factory()->for($membership, 'businessMembership')->create(['weekday' => 2]);

    $this->actingAs($actor, 'sanctum')->postJson($path, ['weekday' => 1, 'breaks' => [
        ['name' => 'Morning rest', 'starts_at' => '10:00', 'ends_at' => '10:15'],
        ['starts_at' => '15:00', 'ends_at' => '15:15'],
    ]])->assertCreated()->assertJsonPath('message', 'Staff breaks added.')->assertJsonPath('data.weekday', 1)
        ->assertJsonPath('data.opens_at', '09:00')->assertJsonPath('data.closes_at', '17:00')
        ->assertJsonCount(3, 'data.breaks')->assertJsonPath('data.breaks.0.name', 'Meal')->assertJsonPath('data.breaks.2.name', null);

    expect($hours->refresh()->breaks)->toHaveCount(3);
    expect($otherDay->refresh()->breaks)->toBeNull();
    $this->assertDatabaseCount('staff_hours', 2);
    $this->getJson($path.'?weekday=1')->assertOk()->assertJsonCount(3, 'data.breaks');
})->with(['owner', 'manager']);

it('replaces or clears only the selected weekdays breaks', function (): void {
    [$actor, , , , $hours, $path] = staffBreakFixture();

    $this->actingAs($actor, 'sanctum')->putJson($path, ['weekday' => 1, 'breaks' => [['starts_at' => '11:00', 'ends_at' => '11:30']]])
        ->assertOk()->assertJsonCount(1, 'data.breaks')->assertJsonPath('data.breaks.0.starts_at', '11:00');
    expect($hours->refresh()->breaks)->toHaveCount(1);
    $this->putJson($path, ['weekday' => 1, 'breaks' => []])->assertOk()->assertJsonPath('data.breaks', []);

    expect($hours->refresh()->breaks)->toBe([]);
    $this->assertDatabaseHas('staff_hours', ['id' => $hours->id, 'opens_at' => '09:00:00', 'closes_at' => '17:00:00', 'is_closed' => false]);
});

it('returns 422 for break additions that conflict with existing breaks or working hours', function (array $breaks, string $message): void {
    [$actor, , , , $hours, $path] = staffBreakFixture();
    $before = $hours->breaks;

    $this->actingAs($actor, 'sanctum')->postJson($path, ['weekday' => 1, 'breaks' => $breaks])->assertUnprocessable()
        ->assertJsonValidationErrors(['breaks' => $message]);

    expect($hours->refresh()->breaks)->toEqual($before);
})->with([
    'overlaps saved break' => [[['starts_at' => '11:30', 'ends_at' => '12:30']], 'Staff breaks must not overlap.'],
    'duplicate saved break' => [[['starts_at' => '12:00', 'ends_at' => '13:00']], 'Staff breaks must not overlap.'],
    'overlaps within batch' => [[['starts_at' => '10:00', 'ends_at' => '11:00'], ['starts_at' => '10:30', 'ends_at' => '11:30']], 'Staff breaks must not overlap.'],
    'before shift' => [[['starts_at' => '08:00', 'ends_at' => '09:00']], 'A break must start within the staff working hours.'],
    'after shift' => [[['starts_at' => '16:30', 'ends_at' => '17:30']], 'A break must end within the staff working hours.'],
]);

it('returns 422 for malformed break requests without updating saved breaks', function (array $payload, string $field): void {
    [$actor, , , , $hours, $path] = staffBreakFixture();
    $before = $hours->breaks;

    $this->actingAs($actor, 'sanctum')->postJson($path, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);

    expect($hours->refresh()->breaks)->toEqual($before);
})->with([
    'missing weekday' => [['breaks' => [['starts_at' => '10:00', 'ends_at' => '10:15']]], 'weekday'],
    'invalid weekday' => [['weekday' => 7, 'breaks' => [['starts_at' => '10:00', 'ends_at' => '10:15']]], 'weekday'],
    'missing breaks' => [['weekday' => 1], 'breaks'],
    'empty addition' => [['weekday' => 1, 'breaks' => []], 'breaks'],
    'non-list breaks' => [['weekday' => 1, 'breaks' => ['rest' => ['starts_at' => '10:00', 'ends_at' => '10:15']]], 'breaks'],
    'missing start' => [['weekday' => 1, 'breaks' => [['ends_at' => '10:15']]], 'breaks.0.starts_at'],
    'missing end' => [['weekday' => 1, 'breaks' => [['starts_at' => '10:00']]], 'breaks.0.ends_at'],
    'bad time' => [['weekday' => 1, 'breaks' => [['starts_at' => '25:00', 'ends_at' => '10:15']]], 'breaks.0.starts_at'],
    'zero length' => [['weekday' => 1, 'breaks' => [['starts_at' => '10:00', 'ends_at' => '10:00']]], 'breaks.0.ends_at'],
    'reversed time' => [['weekday' => 1, 'breaks' => [['starts_at' => '11:00', 'ends_at' => '10:00']]], 'breaks.0.ends_at'],
    'invalid name' => [['weekday' => 1, 'breaks' => [['name' => str_repeat('a', 101), 'starts_at' => '10:00', 'ends_at' => '10:15']]], 'breaks.0.name'],
    'unknown field' => [['weekday' => 1, 'breaks' => [['starts_at' => '10:00', 'ends_at' => '10:15', 'user_id' => 'unexpected']]], 'breaks.0'],
]);

it('returns 422 when a replacement omits the breaks field', function (): void {
    [$actor, , , , $hours, $path] = staffBreakFixture();
    $this->actingAs($actor, 'sanctum')->putJson($path, ['weekday' => 1])->assertUnprocessable()->assertJsonValidationErrors('breaks');
    expect($hours->refresh()->breaks)->toHaveCount(1);
});

it('returns 422 when appending would exceed the total break limit', function (): void {
    [$actor, , , , $hours, $path] = staffBreakFixture();
    $breaks = [];
    for ($minute = 0; $minute < 50; $minute++) {
        $start = Carbon::parse('09:00')->addMinutes($minute * 2);
        $breaks[] = ['starts_at' => $start->format('H:i'), 'ends_at' => $start->addMinute()->format('H:i')];
    }
    $hours->update(['breaks' => $breaks]);

    $this->actingAs($actor, 'sanctum')->postJson($path, ['weekday' => 1, 'breaks' => [['starts_at' => '15:00', 'ends_at' => '15:15']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['breaks' => 'A working day may have at most 50 breaks.']);

    expect($hours->refresh()->breaks)->toHaveCount(50);
});

it('returns 422 when adding breaks to a closed day and permits clearing it', function (): void {
    [$actor, , , , $hours, $path] = staffBreakFixture();
    $hours->update(['is_closed' => true, 'opens_at' => null, 'closes_at' => null, 'breaks' => []]);

    $this->actingAs($actor, 'sanctum')->postJson($path, ['weekday' => 1, 'breaks' => [['starts_at' => '10:00', 'ends_at' => '10:15']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['weekday' => 'Breaks can only be added to a configured working day.']);
    $this->putJson($path, ['weekday' => 1, 'breaks' => []])->assertOk()->assertJsonPath('data.breaks', []);

    expect($hours->refresh()->is_closed)->toBeTrue();
});

it('returns 404 when no working hours exist for the selected weekday', function (string $method): void {
    [$actor, , , , $hours, $path] = staffBreakFixture();

    $this->actingAs($actor, 'sanctum')->json($method, $path.($method === 'GET' ? '?weekday=2' : ''), ['weekday' => 2, 'breaks' => [['starts_at' => '10:00', 'ends_at' => '10:15']]])
        ->assertNotFound()->assertJsonPath('message', 'Staff working hours not found for this weekday.');

    $this->assertDatabaseCount('staff_hours', 1);
})->with(['GET', 'POST', 'PUT']);

it('returns 401 for unauthenticated break requests', function (string $method): void {
    [, , , , $hours, $path] = staffBreakFixture();
    $this->json($method, $path.'?weekday=1', ['weekday' => 1, 'breaks' => []])->assertUnauthorized();
    expect($hours->refresh()->breaks)->toHaveCount(1);
})->with(['GET', 'POST', 'PUT']);

it('lets staff read breaks but returns 403 when they try to change them', function (): void {
    [$actor, , , , $hours, $path] = staffBreakFixture('staff');
    $this->actingAs($actor, 'sanctum')->getJson($path.'?weekday=1')->assertOk()->assertJsonCount(1, 'data.breaks');
    $this->postJson($path, ['weekday' => 1, 'breaks' => []])->assertForbidden();
    $this->putJson($path, ['weekday' => 1, 'breaks' => []])->assertForbidden();
    expect($hours->refresh()->breaks)->toHaveCount(1);
});

it('returns 403 for outsiders and inactive managers', function (bool $outsider, string $method): void {
    [$actor, , , , $hours, $path] = staffBreakFixture('manager', false);
    if ($outsider) {
        $actor = User::factory()->create();
    }
    $this->actingAs($actor, 'sanctum')->json($method, $path.'?weekday=1', ['weekday' => 1, 'breaks' => []])->assertForbidden();
    expect($hours->refresh()->breaks)->toHaveCount(1);
})->with([false, true])->with(['GET', 'POST', 'PUT']);

it('returns 404 for staff outside the business without touching either membership', function (string $method): void {
    [$actor, $business, , , $hours] = staffBreakFixture();
    [, , $otherStaff, , $otherHours] = staffBreakFixture();
    $path = '/api/v1/business/'.$business->id.'/staff/'.$otherStaff->id.'/breaks';

    $this->actingAs($actor, 'sanctum')->json($method, $path.'?weekday=1', ['weekday' => 1, 'breaks' => []])->assertNotFound()
        ->assertJsonPath('message', 'Staff user not found in this business.');

    expect($hours->refresh()->breaks)->toHaveCount(1);
    expect($otherHours->refresh()->breaks)->toHaveCount(1);
})->with(['GET', 'POST', 'PUT']);

it('updates generated availability immediately when breaks are added and cleared independently', function (): void {
    $this->travelTo(Carbon::parse('2026-11-13 08:00', 'UTC'));
    [$actor, $business, , $membership, $hours, $path] = staffBreakFixture();
    $service = $business->services()->firstOrFail();
    $membership->services()->attach($service);
    $availability = '/api/v1/businesses/'.$business->id.'/services/'.$service->id.'/availability?date=2026-11-16';
    $this->actingAs($actor, 'sanctum');

    $response = $this->getJson($availability)->assertOk();
    expect(collect($response->json('data.dates.0.slots'))->pluck('starts_at')->all())->toContain('10:00');
    $this->postJson($path, ['weekday' => 1, 'breaks' => [
        ['starts_at' => '10:00', 'ends_at' => '11:00'], ['starts_at' => '14:00', 'ends_at' => '14:15'],
    ]])->assertCreated();
    $response = $this->getJson($availability)->assertOk();
    expect(collect($response->json('data.dates.0.slots'))->pluck('starts_at')->all())->not->toContain('10:00', '13:30', '14:00')->toContain('11:00');
    expect($hours->refresh()->breaks)->toHaveCount(3);

    $this->putJson($path, ['weekday' => 1, 'breaks' => []])->assertOk();
    $response = $this->getJson($availability)->assertOk();
    expect(collect($response->json('data.dates.0.slots'))->pluck('starts_at')->all())->toContain('10:00', '12:00', '14:00');
    expect($hours->refresh()->breaks)->toBe([]);
});
