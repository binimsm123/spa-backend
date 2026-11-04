<?php

use App\Models\BusinessUser;
use App\Models\StaffHour;
use App\Models\StaffLeave;
use App\Models\User;

function staffScheduleFixture(string $role = 'owner', bool $active = true): array
{
    [$business] = createBusinessWithService();
    $actor = User::factory()->create();
    BusinessUser::query()->create([
        'business_id' => $business->getKey(), 'user_id' => $actor->getKey(), 'role' => $role, 'is_active' => $active,
    ]);
    $staff = User::factory()->create();
    $membership = BusinessUser::query()->create([
        'business_id' => $business->getKey(), 'user_id' => $staff->getKey(), 'role' => 'staff', 'is_active' => true,
    ]);

    return [$actor, $business, $staff, $membership, '/api/v1/business/'.$business->getKey().'/staff/'.$staff->getKey()];
}

function staffWeeklyHours(): array
{
    return array_map(fn (int $weekday): array => [
        'weekday' => $weekday, 'opens_at' => $weekday === 0 ? null : '08:00',
        'closes_at' => $weekday === 0 ? null : '16:00', 'is_closed' => $weekday === 0,
    ], range(0, 6));
}

it('lets owners and managers replace and list a complete staff working week', function (string $role): void {
    [$actor, $business, $staff, $membership, $path] = staffScheduleFixture($role);
    [$otherBusiness] = createBusinessWithService();
    $otherMembership = BusinessUser::query()->create([
        'business_id' => $otherBusiness->getKey(), 'user_id' => $staff->getKey(), 'role' => 'staff', 'is_active' => true,
    ]);
    $otherHour = StaffHour::factory()->for($otherMembership, 'businessMembership')->create(['weekday' => 1]);

    $this->actingAs($actor, 'sanctum')->putJson($path.'/hours', ['hours' => staffWeeklyHours()])
        ->assertOk()->assertJsonCount(7, 'data.hours')->assertJsonPath('data.hours.0.is_closed', true)
        ->assertJsonPath('data.hours.0.opens_at', null)->assertJsonPath('data.hours.1.opens_at', '08:00');
    $replacement = staffWeeklyHours();
    $replacement[1]['opens_at'] = '10:00';
    $this->putJson($path.'/hours', ['hours' => $replacement])->assertOk();
    $this->getJson($path.'/hours')->assertOk()->assertJsonCount(7, 'data.hours')
        ->assertJsonPath('data.hours.1.opens_at', '10:00');

    $this->assertDatabaseHas('staff_hours', ['business_user_id' => $membership->getKey(), 'weekday' => 1, 'opens_at' => '10:00:00']);
    $this->assertDatabaseCount('staff_hours', 8);
    expect($otherHour->refresh()->opens_at->format('H:i'))->toBe('09:00');
    expect($business->hours()->where('weekday', 1)->firstOrFail()->opens_at->format('H:i'))->toBe('09:00');
})->with(['owner', 'manager']);

it('lets staff read an empty schedule and another member schedule', function (): void {
    [$actor, , , $membership, $path] = staffScheduleFixture('staff');

    $this->actingAs($actor, 'sanctum')->getJson($path.'/hours')->assertOk()->assertJsonPath('data.hours', []);
    StaffHour::factory()->closed()->for($membership, 'businessMembership')->create(['weekday' => 0]);
    $this->getJson($path.'/hours')->assertOk()->assertJsonPath('data.hours.0.is_closed', true);
    $this->getJson($path.'/leaves')->assertOk()->assertJsonPath('data.items', []);
});

it('returns 422 for invalid weekly hours without replacing existing hours', function (string $kind, string $field): void {
    [$actor, , , $membership, $path] = staffScheduleFixture();
    StaffHour::factory()->for($membership, 'businessMembership')->create(['weekday' => 1]);
    $hours = staffWeeklyHours();
    match ($kind) {
        'short week' => array_pop($hours),
        'duplicate weekday' => $hours[1]['weekday'] = 0,
        'unknown weekday' => $hours[1]['weekday'] = 7,
        'missing start' => $hours[1]['opens_at'] = null,
        'missing end' => $hours[1]['closes_at'] = null,
        'backwards' => $hours[1]['closes_at'] = '07:00',
        'zero duration' => $hours[1]['closes_at'] = '08:00',
        'bad time' => $hours[1]['opens_at'] = '25:00',
        'invalid closed flag' => $hours[1]['is_closed'] = 'invalid',
    };

    $this->actingAs($actor, 'sanctum')->putJson($path.'/hours', ['hours' => $hours])
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseCount('staff_hours', 1);
    $this->assertDatabaseHas('staff_hours', ['business_user_id' => $membership->getKey(), 'weekday' => 1, 'opens_at' => '09:00:00']);
})->with([
    ['short week', 'hours'], ['duplicate weekday', 'hours.1.weekday'], ['unknown weekday', 'hours.1.weekday'],
    ['missing start', 'hours.1.opens_at'], ['missing end', 'hours.1.closes_at'], ['backwards', 'hours.1.closes_at'],
    ['zero duration', 'hours.1.closes_at'], ['bad time', 'hours.1.opens_at'], ['invalid closed flag', 'hours.1.is_closed'],
]);

it('clears stored times when marking a weekday closed', function (): void {
    [$actor, , , $membership, $path] = staffScheduleFixture();
    StaffHour::factory()->for($membership, 'businessMembership')->create(['weekday' => 1]);
    $hours = staffWeeklyHours();
    $hours[1]['is_closed'] = true;

    $this->actingAs($actor, 'sanctum')->putJson($path.'/hours', ['hours' => $hours])->assertOk()
        ->assertJsonPath('data.hours.1.opens_at', null)->assertJsonPath('data.hours.1.closes_at', null);

    $this->assertDatabaseHas('staff_hours', ['business_user_id' => $membership->getKey(), 'weekday' => 1, 'is_closed' => true, 'opens_at' => null, 'closes_at' => null]);
});

it('creates full-day date ranges and partial-day leave for one staff membership', function (string $role): void {
    [$actor, , , $membership, $path] = staffScheduleFixture($role);

    $this->actingAs($actor, 'sanctum')->postJson($path.'/leaves', ['leaves' => [
        ['starts_on' => '2030-01-10', 'ends_on' => '2030-01-12', 'reason' => 'Annual leave'],
        ['starts_on' => '2030-01-14', 'ends_on' => '2030-01-14', 'starts_at' => '10:30', 'ends_at' => '13:00', 'reason' => 'Appointment'],
    ]])->assertCreated()->assertJsonCount(2, 'data.items')->assertJsonPath('data.items.0.is_full_day', true)
        ->assertJsonPath('data.items.0.starts_on', '2030-01-10')->assertJsonPath('data.items.1.is_full_day', false)
        ->assertJsonPath('data.items.1.starts_at', '10:30')->assertJsonPath('data.items.1.ends_at', '13:00');

    $this->assertDatabaseCount('staff_leaves', 2);
    $this->assertDatabaseHas('staff_leaves', ['business_user_id' => $membership->getKey(), 'starts_on' => '2030-01-10', 'ends_on' => '2030-01-12', 'starts_at' => null, 'ends_at' => null]);
    $this->assertDatabaseHas('staff_leaves', ['business_user_id' => $membership->getKey(), 'starts_at' => '10:30:00', 'ends_at' => '13:00:00']);
})->with(['owner', 'manager']);

it('lists only this memberships leaves with inclusive date-range filters and pagination', function (): void {
    [$actor, , , $membership, $path] = staffScheduleFixture('staff');
    StaffLeave::factory()->for($membership, 'businessMembership')->create(['starts_on' => '2030-01-01', 'ends_on' => '2030-01-10']);
    $partial = StaffLeave::factory()->partialDay()->for($membership, 'businessMembership')->create(['starts_on' => '2030-01-10', 'ends_on' => '2030-01-10']);
    StaffLeave::factory()->for($membership, 'businessMembership')->create(['starts_on' => '2030-02-01', 'ends_on' => '2030-02-01']);
    [, , , $otherMembership] = staffScheduleFixture();
    StaffLeave::factory()->for($otherMembership, 'businessMembership')->create(['starts_on' => '2030-01-10', 'ends_on' => '2030-01-10']);

    $this->actingAs($actor, 'sanctum')->getJson($path.'/leaves')->assertOk()->assertJsonCount(3, 'data.items');
    $this->getJson($path.'/leaves?from=2030-01-10&to=2030-01-10&per_page=1&page=2')->assertOk()
        ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $partial->getKey())
        ->assertJsonPath('data.pagination.total', 2);
    $this->getJson($path.'/leaves?to=2030-01-09')->assertOk()->assertJsonCount(1, 'data.items');
    $this->getJson($path.'/leaves?from=2030-02-01')->assertOk()->assertJsonCount(1, 'data.items');
});

it('updates leave fields without changing omitted values or another membership', function (): void {
    [$actor, , , $membership, $path] = staffScheduleFixture();
    $leave = StaffLeave::factory()->partialDay()->for($membership, 'businessMembership')->create(['starts_on' => '2030-01-10', 'ends_on' => '2030-01-10']);
    [, , , $otherMembership] = staffScheduleFixture();

    $this->actingAs($actor, 'sanctum')->patchJson($path.'/leaves/'.$leave->getKey(), ['reason' => 'Updated', 'business_user_id' => $otherMembership->getKey()])
        ->assertOk()->assertJsonPath('data.reason', 'Updated')->assertJsonPath('data.starts_at', '10:00')->assertJsonPath('data.ends_on', '2030-01-10');

    $this->assertDatabaseHas('staff_leaves', ['id' => $leave->getKey(), 'business_user_id' => $membership->getKey(), 'reason' => 'Updated']);
    $this->patchJson($path.'/leaves/'.$leave->getKey(), ['starts_at' => null, 'ends_at' => null, 'ends_on' => '2030-01-12'])
        ->assertOk()->assertJsonPath('data.is_full_day', true);
    $this->assertDatabaseHas('staff_leaves', ['id' => $leave->getKey(), 'starts_at' => null, 'ends_at' => null, 'ends_on' => '2030-01-12']);
});

it('returns 422 when a partial leave update would create an invalid interval', function (): void {
    [$actor, , , $membership, $path] = staffScheduleFixture();
    $leave = StaffLeave::factory()->partialDay()->for($membership, 'businessMembership')->create(['starts_on' => '2030-01-10', 'ends_on' => '2030-01-10']);

    $this->actingAs($actor, 'sanctum')->patchJson($path.'/leaves/'.$leave->getKey(), ['starts_at' => '13:00'])
        ->assertUnprocessable()->assertJsonValidationErrors(['ends_at' => 'The leave end time must be after the start time.']);

    $this->assertDatabaseHas('staff_leaves', ['id' => $leave->getKey(), 'starts_at' => '10:00:00', 'ends_at' => '12:00:00']);
});

it('returns 422 for invalid leave without saving any records in the batch', function (array $row, string $field): void {
    [$actor, , , , $path] = staffScheduleFixture();

    $this->actingAs($actor, 'sanctum')->postJson($path.'/leaves', ['leaves' => [
        ['starts_on' => '2030-01-01', 'ends_on' => '2030-01-01'], $row,
    ]])->assertUnprocessable()->assertJsonValidationErrors('leaves.1.'.$field);

    $this->assertDatabaseCount('staff_leaves', 0);
})->with([
    'missing dates' => [[], 'starts_on'],
    'invalid date' => [['starts_on' => '2030-02-30', 'ends_on' => '2030-03-01'], 'starts_on'],
    'end before start' => [['starts_on' => '2030-01-02', 'ends_on' => '2030-01-01'], 'ends_on'],
    'start without end time' => [['starts_on' => '2030-01-01', 'ends_on' => '2030-01-01', 'starts_at' => '10:00'], 'ends_at'],
    'end without start time' => [['starts_on' => '2030-01-01', 'ends_on' => '2030-01-01', 'ends_at' => '12:00'], 'starts_at'],
    'zero duration' => [['starts_on' => '2030-01-01', 'ends_on' => '2030-01-01', 'starts_at' => '10:00', 'ends_at' => '10:00'], 'ends_at'],
    'backwards time' => [['starts_on' => '2030-01-01', 'ends_on' => '2030-01-01', 'starts_at' => '12:00', 'ends_at' => '10:00'], 'ends_at'],
    'overnight partial leave' => [['starts_on' => '2030-01-01', 'ends_on' => '2030-01-02', 'starts_at' => '10:00', 'ends_at' => '12:00'], 'ends_on'],
    'long reason' => [['starts_on' => '2030-01-01', 'ends_on' => '2030-01-01', 'reason' => str_repeat('a', 256)], 'reason'],
]);

it('returns 422 when creating leaves without a nonempty list', function (array $payload): void {
    [$actor, , , , $path] = staffScheduleFixture();

    $this->actingAs($actor, 'sanctum')->postJson($path.'/leaves', $payload)->assertUnprocessable()->assertJsonValidationErrors('leaves');

    $this->assertDatabaseCount('staff_leaves', 0);
})->with(['missing' => [[]], 'empty' => [['leaves' => []]], 'not a list' => [['leaves' => ['day' => []]]]]);

it('returns 422 for invalid leave filters', function (string $query, string $field): void {
    [$actor, , , , $path] = staffScheduleFixture();

    $this->actingAs($actor, 'sanctum')->getJson($path.'/leaves?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([['page=0', 'page'], ['per_page=101', 'per_page'], ['from=invalid', 'from'], ['from=2030-02-01&to=2030-01-01', 'to']]);

it('deletes a leave without deleting other leave records', function (): void {
    [$actor, , , $membership, $path] = staffScheduleFixture('manager');
    $leave = StaffLeave::factory()->for($membership, 'businessMembership')->create();
    $otherLeave = StaffLeave::factory()->for($membership, 'businessMembership')->create();

    $this->actingAs($actor, 'sanctum')->deleteJson($path.'/leaves/'.$leave->getKey())->assertOk()->assertJsonPath('data', null);

    $this->assertModelMissing($leave);
    $this->assertModelExists($otherLeave);
});

it('returns 401 for unauthenticated schedule requests', function (string $method, string $suffix): void {
    [, , , $membership, $path] = staffScheduleFixture();
    $leave = StaffLeave::factory()->for($membership, 'businessMembership')->create();

    $this->json($method, $path.str_replace('{leave}', $leave->getKey(), $suffix))->assertUnauthorized();

    $this->assertModelExists($leave);
})->with([
    ['GET', '/hours'], ['PUT', '/hours'], ['GET', '/leaves'], ['POST', '/leaves'],
    ['PATCH', '/leaves/{leave}'], ['DELETE', '/leaves/{leave}'],
]);

it('returns 403 when staff attempt to change schedules', function (string $method, string $suffix): void {
    [$actor, , , $membership, $path] = staffScheduleFixture('staff');
    $leave = StaffLeave::factory()->for($membership, 'businessMembership')->create();

    $this->actingAs($actor, 'sanctum')->json($method, $path.str_replace('{leave}', $leave->getKey(), $suffix))->assertForbidden();

    $this->assertDatabaseCount('staff_hours', 0);
    $this->assertDatabaseCount('staff_leaves', 1);
    $this->assertModelExists($leave);
})->with([['PUT', '/hours'], ['POST', '/leaves'], ['PATCH', '/leaves/{leave}'], ['DELETE', '/leaves/{leave}']]);

it('returns 403 when inactive members or outsiders access schedules', function (string $method, string $suffix, bool $outsider): void {
    [$actor, , , $membership, $path] = staffScheduleFixture('owner', false);
    $leave = StaffLeave::factory()->for($membership, 'businessMembership')->create();
    if ($outsider) {
        $actor = User::factory()->create();
    }

    $this->actingAs($actor, 'sanctum')->json($method, $path.str_replace('{leave}', $leave->getKey(), $suffix))->assertForbidden();

    $this->assertModelExists($leave);
    $this->assertDatabaseCount('staff_hours', 0);
})->with([
    ['GET', '/hours'], ['PUT', '/hours'], ['GET', '/leaves'], ['POST', '/leaves'],
    ['PATCH', '/leaves/{leave}'], ['DELETE', '/leaves/{leave}'],
])->with([false, true]);

it('returns 404 for a staff user outside the selected business', function (string $method, string $suffix): void {
    [$actor, $business] = staffScheduleFixture();
    [, , $otherStaff, $membership] = staffScheduleFixture();
    $leave = StaffLeave::factory()->for($membership, 'businessMembership')->create();
    $path = '/api/v1/business/'.$business->getKey().'/staff/'.$otherStaff->getKey();

    $this->actingAs($actor, 'sanctum')->json($method, $path.str_replace('{leave}', $leave->getKey(), $suffix))
        ->assertNotFound()->assertJsonPath('message', 'Staff user not found in this business.');

    $this->assertModelExists($leave);
})->with([
    ['GET', '/hours'], ['PUT', '/hours'], ['GET', '/leaves'], ['POST', '/leaves'],
    ['PATCH', '/leaves/{leave}'], ['DELETE', '/leaves/{leave}'],
]);

it('returns 404 when accessing a leave assigned to another staff member or business', function (string $method, bool $otherBusiness): void {
    [$actor, $business, , , $path] = staffScheduleFixture();
    [, , , $membership] = staffScheduleFixture();
    if (! $otherBusiness) {
        $membership = BusinessUser::query()->create([
            'business_id' => $business->getKey(), 'user_id' => User::factory()->create()->getKey(), 'role' => 'staff', 'is_active' => true,
        ]);
    }
    $leave = StaffLeave::factory()->for($membership, 'businessMembership')->create();

    $this->actingAs($actor, 'sanctum')->json($method, $path.'/leaves/'.$leave->getKey(), ['starts_on' => 'invalid'])
        ->assertNotFound()->assertJsonPath('message', 'Staff leave not found in this business.');

    $this->assertModelExists($leave);
})->with(['PATCH', 'DELETE'])->with([false, true]);

it('returns clear 404 messages for missing schedule records', function (string $resource, string $message): void {
    [$actor, $business, $staff, , $path] = staffScheduleFixture();
    $missing = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $url = match ($resource) {
        'business' => '/api/v1/business/'.$missing.'/staff/'.$staff->getKey().'/hours',
        'user' => '/api/v1/business/'.$business->getKey().'/staff/'.$missing.'/hours',
        'leave' => $path.'/leaves/'.$missing,
    };

    $this->actingAs($actor, 'sanctum')->json($resource === 'leave' ? 'DELETE' : 'GET', $url)
        ->assertNotFound()->assertJsonPath('message', $message);
})->with([
    ['business', 'Business not found.'], ['user', 'Staff user not found in this business.'], ['leave', 'Staff leave not found in this business.'],
]);
