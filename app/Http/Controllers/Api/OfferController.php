<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Dto\Api\OffersData;
use App\Http\Requests\Offer\RedeemOfferRequest;
use App\Http\Resources\OfferRedemptionResource;
use App\Http\Resources\OfferResource;
use App\Models\Business;
use App\Models\Offer;
use App\Models\Service;
use App\Services\IdempotencyService;
use App\Services\OfferService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OfferController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OfferService $offers,
        private readonly IdempotencyService $idempotency,
    ) {}

    /**
     * GET /offers — active/upcoming/expired for the customer.
     */
    public function index(Request $request): JsonResponse
    {
        $scope = $request->query('scope', 'active');
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $now = now();

        $query = Offer::query()
            ->where('status', '!=', 'paused')
            ->where(function ($q) {
                $q->whereNull('business_id')->orWhereHas('business', fn ($b) => $b->where('status', 'active'));
            });

        match ($scope) {
            'upcoming' => $query->where('starts_at', '>', $now),
            'expired' => $query->where('expires_at', '<', $now),
            default => $query->where('status', 'active')->where('starts_at', '<=', $now)->where('expires_at', '>=', $now),
        };

        $offers = $query->orderBy('expires_at')->paginate($perPage);

        $page = $offers->toArray();

        return $this->success((new OffersData(
            OfferResource::collection($offers->getCollection())->resolve(),
            $page['current_page'], $page['per_page'], $page['total'], $page['last_page'],
        ))->toArray(), 'Offers loaded.');
    }

    /**
     * POST /offers/{offer}/redeem — validation only; creates a short-lived reservation.
     */
    public function redeem(RedeemOfferRequest $request, Offer $offer): JsonResponse
    {
        $user = $request->user();
        $serviceId = $request->validated('service_id');
        $hash = IdempotencyService::requestHash(['offer' => $offer->getKey(), 'service' => $serviceId, 'user' => $user->getKey()]);

        [$status, $data, $replayed] = $this->idempotency->run(
            $user->getKey(),
            'offer.redeem',
            $request->header('Idempotency-Key'),
            $hash,
            function () use ($user, $offer, $serviceId): array {
                $service = Service::query()->findOrFail($serviceId);

                $redemption = $this->offers->redeem($user, $offer, $service);

                return [
                    Response::HTTP_CREATED,
                    OfferRedemptionResource::make($redemption->load('offer'))->resolve(),
                ];
            },
        );

        return $this->success($data, $replayed ? 'Redemption replayed.' : 'Offer is ready to redeem.', Response::HTTP_OK);
    }

    /**
     * GET /offers/{offer} — public detail.
     */
    public function show(Offer $offer): JsonResponse
    {
        return $this->resource(OfferResource::make($offer));
    }
}
