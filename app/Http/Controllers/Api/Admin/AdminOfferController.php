<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\UpsertOfferRequest;
use App\Http\Resources\OfferResource;
use App\Models\Offer;
use App\Support\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOfferController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $query = Offer::query()
            ->with('business')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('business_id'), fn ($q, $id) => $q->where('business_id', $id))
            ->orderByDesc('created_at');

        $offers = $query->paginate($perPage);

        return $this->paginated(OfferResource::collection($offers));
    }

    public function store(UpsertOfferRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['is_platform_sponsored'] = true;
        $data['created_by_user_id'] = $request->user()->getKey();

        $offer = Offer::query()->create($data);

        AuditLogger::record($request->user(), 'offer.created', 'offer', $offer->getKey(), null, $offer->only(['code', 'title', 'discount_type', 'discount_value']));

        return $this->resource(OfferResource::make($offer), 'Offer created.', 201);
    }

    public function update(UpsertOfferRequest $request, Offer $offer): JsonResponse
    {
        $before = $offer->only(['code', 'title', 'status', 'discount_type', 'discount_value', 'starts_at', 'expires_at']);
        $offer->fill($request->validated())->save();

        AuditLogger::record($request->user(), 'offer.updated', 'offer', $offer->getKey(), $before, $offer->only(['code', 'title', 'status', 'discount_type', 'discount_value', 'starts_at', 'expires_at']));

        return $this->resource(OfferResource::make($offer->refresh()), 'Offer updated.');
    }

    public function expire(Request $request, Offer $offer): JsonResponse
    {
        $before = $offer->status?->value;
        $offer->forceFill(['status' => 'expired'])->save();

        AuditLogger::record($request->user(), 'offer.expired', 'offer', $offer->getKey(), ['status' => $before], ['status' => 'expired']);

        return $this->resource(OfferResource::make($offer->refresh()), 'Offer expired.');
    }
}
