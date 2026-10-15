<?php

namespace App\Http\Controllers\Api\Business;

use App\Enums\BusinessUserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\RegisterBusinessRequest;
use App\Http\Requests\Business\UpsertBusinessRequest;
use App\Http\Resources\BusinessResource;
use App\Models\Business;
use App\Models\BusinessClosure;
use App\Models\BusinessHour;
use App\Services\BusinessService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BusinessProfileController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BusinessService $businesses) {}

    public function register(RegisterBusinessRequest $request): JsonResponse
    {
        // dd($request->all());
        $business = $this->businesses->register($request->user(), $request->validated());
        $data = BusinessResource::make($business)->resolve();
        $data['kyc'] = $business->kycSummary();
        $data['personal_kyc'] = $request->user()->kycSummary();
        $data['my_role'] = BusinessUserRole::Owner->value;
        $data['user_roles'] = $request->user()->getRoleNames();

        return $this->created($data, 'Business registration submitted for verification. It might take upto 3 days to verify!');
    }

    public function document(Request $request, Business $business, string $document): StreamedResponse
    {
        $this->assertMember($request, $business, ['owner']);
        $path = $this->businesses->documentPath($business, $document);
        abort_unless($path, 404);

        return Storage::disk('local')->response($path, $document.'.'.pathinfo($path, PATHINFO_EXTENSION), ['Cache-Control' => 'private, no-store']);
    }

    /**
     * GET /business/my — businesses the user manages.
     */
    public function myBusinesses(Request $request): JsonResponse
    {
        $businesses = $this->businesses->managedBy($request->user());

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

        $business = $this->businesses->profile($business);

        $data = BusinessResource::make($business)->resolve();

        if ($request->user()->hasBusinessRole($business, BusinessUserRole::Owner)) {
            $data['kyc'] = $business->kycSummary();
        }

        return $this->success($data);
    }

    public function update(UpsertBusinessRequest $request, Business $business): JsonResponse
    {
        $this->assertMember($request, $business, ['owner', 'manager']);

        $business = $this->businesses->update($business, $request->validated());

        return $this->resource(BusinessResource::make($business), 'Business updated.');
    }

    public function updateHours(Request $request, Business $business): JsonResponse
    {
        $this->assertMember($request, $business, ['owner', 'manager', 'staff']);

        $data = $request->validate([
            'hours' => ['required', 'array', 'size:7'],
            'hours.*.weekday' => ['required', 'integer', 'between:0,6'],
            'hours.*.opens_at' => ['nullable', 'date_format:H:i'],
            'hours.*.closes_at' => ['nullable', 'date_format:H:i'],
            'hours.*.is_closed' => ['required', 'boolean'],
        ]);

        $business = $this->businesses->updateHours($business, $data['hours']);

        return $this->success([
            'hours' => $business->hours->map(fn (BusinessHour $h) => [
                'weekday' => (int) $h->weekday,
                'opens_at' => $h->opens_at?->format('H:i'),
                'closes_at' => $h->closes_at?->format('H:i'),
                'is_closed' => (bool) $h->is_closed,
            ]),
        ], 'Opening hours updated.');
    }

    public function addClosures(Request $request, Business $business): JsonResponse
    {
        $this->assertMember($request, $business, ['owner', 'manager', 'staff']);

        $data = $request->validate([
            'closures' => ['required', 'array', 'min:1'],
            'closures.*.starts_on' => ['required', 'date'],
            'closures.*.ends_on' => ['required', 'date', 'after_or_equal:closures.*.starts_on'],
            'closures.*.reason' => ['nullable', 'string', 'max:255'],
        ]);

        $created = $this->businesses->addClosures($business, $data['closures']);

        return $this->success(['items' => $created->map(fn (BusinessClosure $c) => $c->only(['id', 'starts_on', 'ends_on', 'reason']))], 'Closures added.');
    }

    public function removeClosure(Request $request, Business $business, BusinessClosure $closure): JsonResponse
    {
        $this->assertMember($request, $business, ['owner', 'manager', 'staff']);
        abort_unless($closure->business_id === $business->getKey(), 404);

        $this->businesses->deleteClosure($closure);

        return $this->success(null, 'Closure removed.');
    }

    private function assertMember(Request $request, Business $business, ?array $roles = null): void
    {
        $user = $request->user();

        abort_unless($user->belongsToBusiness($business), 403, 'You do not manage this business.');

        if ($roles !== null && ! $user->hasBusinessRole($business, ...array_map(
            fn (string $r) => BusinessUserRole::from($r),
            $roles,
        ))) {
            abort(403, 'Your role cannot perform this action.');
        }
    }
}
