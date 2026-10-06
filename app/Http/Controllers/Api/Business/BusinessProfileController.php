<?php

namespace App\Http\Controllers\Api\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\UpsertBranchRequest;
use App\Http\Requests\Business\UpsertBusinessRequest;
use App\Http\Resources\BusinessLocationResource;
use App\Http\Resources\BusinessResource;
use App\Models\Business;
use App\Models\BusinessClosure;
use App\Models\BusinessHour;
use App\Models\BusinessLocation;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessProfileController extends Controller
{
    use ApiResponse;

    /**
     * GET /business/my — businesses the user manages.
     */
    public function myBusinesses(Request $request): JsonResponse
    {
        $businesses = $request->user()->businesses()
            ->withPivot('role', 'is_active')
            ->wherePivot('is_active', true)
            ->with('locations')
            ->get();

        return $this->success([
            'items' => collect($businesses)->map(fn (Business $b) => array_merge(
                BusinessResource::make($b)->resolve(),
                ['my_role' => $b->pivot->role ?? null],
            )),
        ]);
    }

    /**
     * GET /business/{business} — profile for a business the user manages.
     */
    public function show(Request $request, Business $business): JsonResponse
    {
        $this->assertMember($request, $business);

        $business->load(['locations.hours', 'locations.closures']);

        return $this->resource(BusinessResource::make($business));
    }

    public function update(UpsertBusinessRequest $request, Business $business): JsonResponse
    {
        $this->assertMember($request, $business, ['owner', 'manager']);

        $business->fill($request->validated())->save();

        if ($request->boolean('is_online') !== false && $request->has('is_online')) {
            $business->is_online = $request->boolean('is_online');
            $business->save();
        }

        return $this->resource(BusinessResource::make($business->refresh()), 'Business updated.');
    }

    public function storeBranch(UpsertBranchRequest $request, Business $business): JsonResponse
    {
        $this->assertMember($request, $business, ['owner', 'manager']);

        /** @var BusinessLocation $branch */
        $branch = $business->locations()->create($request->validated());

        // Default: open 10:00-19:00 Mon-Sat, closed Sunday.
        foreach (range(0, 6) as $weekday) {
            BusinessHour::query()->create([
                'business_location_id' => $branch->getKey(),
                'weekday' => $weekday,
                'opens_at' => $weekday === 0 ? null : '10:00',
                'closes_at' => $weekday === 0 ? null : '19:00',
                'is_closed' => $weekday === 0,
            ]);
        }

        return $this->resource(BusinessLocationResource::make($branch), 'Branch created.', 201);
    }

    public function updateBranch(UpsertBranchRequest $request, Business $business, BusinessLocation $branch): JsonResponse
    {
        $this->assertMember($request, $business, ['owner', 'manager']);
        abort_unless($branch->business_id === $business->getKey(), 404);

        $branch->fill($request->validated())->save();

        return $this->resource(BusinessLocationResource::make($branch->refresh()), 'Branch updated.');
    }

    public function updateHours(Request $request, Business $business, BusinessLocation $branch): JsonResponse
    {
        $this->assertMember($request, $business, ['owner', 'manager', 'staff']);
        abort_unless($branch->business_id === $business->getKey(), 404);

        $data = $request->validate([
            'hours' => ['required', 'array', 'size:7'],
            'hours.*.weekday' => ['required', 'integer', 'between:0,6'],
            'hours.*.opens_at' => ['nullable', 'date_format:H:i'],
            'hours.*.closes_at' => ['nullable', 'date_format:H:i'],
            'hours.*.is_closed' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($data, $branch): void {
            foreach ($data['hours'] as $row) {
                BusinessHour::query()->updateOrCreate(
                    ['business_location_id' => $branch->getKey(), 'weekday' => $row['weekday']],
                    [
                        'opens_at' => $row['is_closed'] ? null : $row['opens_at'] ?? null,
                        'closes_at' => $row['is_closed'] ? null : $row['closes_at'] ?? null,
                        'is_closed' => $row['is_closed'],
                    ],
                );
            }
        });

        $branch->refresh()->load('hours');

        return $this->success([
            'hours' => $branch->hours->map(fn (BusinessHour $h) => [
                'weekday' => (int) $h->weekday,
                'opens_at' => $h->opens_at?->format('H:i'),
                'closes_at' => $h->closes_at?->format('H:i'),
                'is_closed' => (bool) $h->is_closed,
            ]),
        ], 'Opening hours updated.');
    }

    public function addClosures(Request $request, Business $business, BusinessLocation $branch): JsonResponse
    {
        $this->assertMember($request, $business, ['owner', 'manager', 'staff']);
        abort_unless($branch->business_id === $business->getKey(), 404);

        $data = $request->validate([
            'closures' => ['required', 'array', 'min:1'],
            'closures.*.starts_on' => ['required', 'date'],
            'closures.*.ends_on' => ['required', 'date', 'after_or_equal:closures.*.starts_on'],
            'closures.*.reason' => ['nullable', 'string', 'max:255'],
        ]);

        $created = collect($data['closures'])
            ->map(fn (array $row) => BusinessClosure::query()->create($row + ['business_location_id' => $branch->getKey()]));

        return $this->success(['items' => $created->map(fn (BusinessClosure $c) => $c->only(['id', 'starts_on', 'ends_on', 'reason']))], 'Closures added.');
    }

    public function removeClosure(Request $request, Business $business, BusinessLocation $branch, BusinessClosure $closure): JsonResponse
    {
        $this->assertMember($request, $business, ['owner', 'manager', 'staff']);
        abort_unless($branch->business_id === $business->getKey() && $closure->business_location_id === $branch->getKey(), 404);

        $closure->delete();

        return $this->success(null, 'Closure removed.');
    }

    private function assertMember(Request $request, Business $business, array $roles = null): void
    {
        $user = $request->user();

        abort_unless($user->belongsToBusiness($business), 403, 'You do not manage this business.');

        if ($roles !== null && ! $user->hasBusinessRole($business, ...array_map(
            fn (string $r) => \App\Enums\BusinessUserRole::from($r),
            $roles,
        ))) {
            abort(403, 'Your role cannot perform this action.');
        }
    }
}
