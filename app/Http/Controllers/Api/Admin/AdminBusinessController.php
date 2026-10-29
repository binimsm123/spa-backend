<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BusinessStateRequest;
use App\Http\Requests\Business\UpsertBusinessRequest;
use App\Http\Resources\BusinessResource;
use App\Models\Business;
use App\Models\BusinessOnboarding;
use App\Services\BusinessOnboardingService;
use App\Services\BusinessService;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminBusinessController extends Controller
{
    use ApiResponse;

    public function document(Business $business, string $document): StreamedResponse
    {
        $path = in_array($document, Business::KYC_DOCUMENT_TYPES, true) ? ($business->kyc_documents[$document] ?? null) : null;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $document.'.'.pathinfo($path, PATHINFO_EXTENSION), ['Cache-Control' => 'private, no-store']);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', 'string', 'in:pending,active,suspended'],
            'verification' => ['sometimes', 'string', 'in:verified,unverified'],
            'online' => ['sometimes', 'boolean'],
            'created_from' => ['sometimes', 'date_format:Y-m-d'],
            'created_to' => ['sometimes', 'date_format:Y-m-d'],
            'signup_from' => ['sometimes', 'string', 'in:web,android,ios'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $perPage = (int) ($filters['per_page'] ?? 20);

        $query = Business::query()->with(['locations', 'categories'])
            ->when($filters['q'] ?? null, function ($q, $search): void {
                $like = '%'.strtolower($search).'%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(slug) LIKE ?', [$like])
                    ->orWhereHas('locations', fn ($locations) => $locations->whereRaw('LOWER(address) LIKE ?', [$like])->orWhereRaw('LOWER(city) LIKE ?', [$like])));
            })
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['verification'] ?? null) === 'verified', fn ($q) => $q->where('is_verified', true))
            ->when(($filters['verification'] ?? null) === 'unverified', fn ($q) => $q->where('is_verified', false))
            ->when(isset($filters['online']), fn ($q) => $q->where('is_online', $filters['online']))
            ->when(isset($filters['created_from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['created_from']))
            ->when(isset($filters['created_to']), fn ($q) => $q->whereDate('created_at', '<=', $filters['created_to']))
            ->when(isset($filters['signup_from']), fn ($q) => $q->where('signup_from', $filters['signup_from']))
            ->orderByDesc('created_at');

        return $this->paginated(BusinessResource::collection($query->paginate($perPage)));
    }

    public function show(Request $request, Business $business): JsonResponse
    {
        $business->load(['locations', 'categories', 'hours', 'closures', 'services.category', 'services.currentPrices', 'members']);

        $data = BusinessResource::make($business)->resolve();
        $data['kyc'] = $business->kycSummary('admin.businesses.documents.show');
        $application = BusinessOnboarding::where('business_id', $business->id)->first();
        if ($application) {
            $data['onboarding'] = $application->summary();
            foreach ($data['onboarding']['documents'] as &$document) {
                $document['view_url'] = route('admin.businesses.onboarding.document', ['business' => $business->id, 'document' => $document['kind']]);
            }
            unset($document);
        }
        $data['members'] = $business->members->map(fn ($u) => [
            'id' => $u->id,
            'display_name' => $u->display_name,
            'mobile_number' => $u->mobile,
            'role' => $u->pivot->role ?? null,
            'is_active' => (bool) ($u->pivot->is_active ?? false),
        ]);

        return $this->success($data);
    }

    public function update(UpsertBusinessRequest $request, Business $business, BusinessService $businesses): JsonResponse
    {
        $before = $business->only(['name', 'about', 'phone_number', 'status', 'is_verified', 'is_online']);
        $updated = $businesses->update($business, $request->validated(), adminOverride: true);
        AuditLogger::record(
            $request->user(),
            'business.updated',
            'business',
            $updated->getKey(),
            $before,
            $updated->only(['name', 'about', 'phone_number', 'status', 'is_verified', 'is_online']),
        );

        return $this->resource(BusinessResource::make($updated), 'Business updated.');
    }

    public function changeState(BusinessStateRequest $request, Business $business): JsonResponse
    {
        $data = $request->validated();
        if (in_array($data['action'], ['verify', 'reject'], true) && BusinessOnboarding::where('business_id', $business->id)->exists()) {
            abort_unless($request->user()->can('admin.businesses.verify'), 403);
            app(BusinessOnboardingService::class)->review($request->user(), $business, $data['action'], $data['reason'] ?? null);

            return $this->resource(BusinessResource::make($business->refresh()), 'Business updated.');
        }
        $before = $business->only(['status', 'is_verified', 'is_online']);

        match ($data['action']) {
            'verify' => $business->forceFill(['is_verified' => true, 'status' => 'active'])->save(),
            'reject' => $business->forceFill(['is_verified' => false, 'status' => 'pending'])->save(),
            'suspend' => $business->forceFill(['status' => 'suspended', 'is_online' => false])->save(),
            'reactivate' => $business->forceFill(['status' => 'active'])->save(),
            'online' => $business->forceFill(['is_online' => true])->save(),
            'offline' => $business->forceFill(['is_online' => false])->save(),
        };

        AuditLogger::record(
            $request->user(),
            'business.'.$data['action'],
            'business',
            $business->getKey(),
            $before,
            $business->only(['status', 'is_verified', 'is_online']),
            $data['reason'] ?? null,
        );

        return $this->resource(BusinessResource::make($business->refresh()), 'Business updated.');
    }
}
