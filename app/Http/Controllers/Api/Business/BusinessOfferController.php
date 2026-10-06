<?php

namespace App\Http\Controllers\Api\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\UpsertOfferRequest;
use App\Http\Resources\OfferResource;
use App\Models\Business;
use App\Models\Offer;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessOfferController extends Controller
{
    use ApiResponse;

    public function index(Request $request, Business $business): JsonResponse
    {
        $this->assertMember($request, $business);

        $offers = $business->offers()->withCount('redemptions as redemption_count')->latest()->get();

        return $this->success(['items' => collect($offers)->map(function (Offer $offer) {
            $data = OfferResource::make($offer)->resolve();
            $data['redemption_count'] = (int) $offer->redemption_count;
            $data['remaining_usage'] = $offer->total_usage_limit !== null
                ? max(0, $offer->total_usage_limit - $offer->usage_count)
                : null;

            return $data;
        })]);
    }

    public function store(UpsertOfferRequest $request, Business $business): JsonResponse
    {
        $this->assertMember($request, $business, ['owner', 'manager']);

        $data = $request->validated();
        $data['business_id'] = $business->getKey();
        $data['created_by_user_id'] = $request->user()->getKey();

        $offer = $business->offers()->create($data);

        return $this->resource(OfferResource::make($offer), 'Offer created.', 201);
    }

    public function update(UpsertOfferRequest $request, Business $business, Offer $offer): JsonResponse
    {
        $this->assertMember($request, $business, ['owner', 'manager']);
        abort_unless($offer->business_id === $business->getKey(), 404);

        $offer->fill($request->validated())->save();

        return $this->resource(OfferResource::make($offer->refresh()), 'Offer updated.');
    }

    public function destroy(Request $request, Business $business, Offer $offer): JsonResponse
    {
        $this->assertMember($request, $business, ['owner']);
        abort_unless($offer->business_id === $business->getKey(), 404);

        $offer->delete();

        return $this->success(null, 'Offer deleted.');
    }

    private function assertMember(Request $request, Business $business, ?array $roles = null): void
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
