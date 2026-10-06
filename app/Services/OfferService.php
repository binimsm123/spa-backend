<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\OfferStatus;
use App\Models\Booking;
use App\Models\CheckoutQuote;
use App\Models\Offer;
use App\Models\OfferRedemption;
use App\Models\Service;
use App\Models\User;
use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OfferService
{
    public function __construct(
        private readonly RewardService $rewards,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Validate eligibility and create a short-lived redemption reservation
     * (no booking created, no charge).
     */
    public function redeem(User $user, Offer $offer, Service $service, ?string $idempotencyKey = null): OfferRedemption
    {
        return DB::transaction(function () use ($user, $offer, $service, $idempotencyKey) {
            $this->assertEligible($user, $offer, $service);

            /** @var OfferRedemption $redemption */
            $redemption = OfferRedemption::query()->create([
                'offer_id' => $offer->getKey(),
                'user_id' => $user->getKey(),
                'service_id' => $service->getKey(),
                'idempotency_key' => $idempotencyKey ?? (string) Str::ulid(),
                'discount_minor' => 0,
                'points_used' => 0,
                'status' => 'reserved',
                'expires_at' => now()->addMinutes((int) config('spa.offers.redemption_ttl_minutes', 30)),
            ]);

            if ($offer->requires_reward_points) {
                $this->rewards->spendPoints(
                    $user,
                    (int) $offer->reward_points_cost,
                    $redemption,
                    'Redeemed offer '.$offer->code,
                );

                $redemption->forceFill(['points_used' => (int) $offer->reward_points_cost])->save();
            }

            return $redemption;
        });
    }

    /**
     * Apply a redemption to a quote/booking inside the checkout transaction.
     */
    public function applyToQuote(User $user, Offer $offer, Booking $booking, int $subtotalMinor): OfferRedemption
    {
        return DB::transaction(function () use ($user, $offer, $booking, $subtotalMinor) {
            $this->assertEligible($user, $offer, $booking->items->first()->service ?? null, $subtotalMinor);

            $discount = $this->calculateDiscount($offer, $subtotalMinor);

            /** @var OfferRedemption $redemption */
            $redemption = OfferRedemption::query()->create([
                'offer_id' => $offer->getKey(),
                'user_id' => $user->getKey(),
                'booking_id' => $booking->getKey(),
                'service_id' => $booking->items->first()->service_id ?? null,
                'idempotency_key' => (string) Str::ulid(),
                'discount_minor' => $discount,
                'points_used' => 0,
                'status' => 'applied',
                'redeemed_at' => now(),
            ]);

            $offer->increment('usage_count');

            return $redemption;
        });
    }

    /**
     * Release an applied redemption (booking cancelled/refunded): refund points, restore limits.
     */
    public function release(OfferRedemption $redemption): void
    {
        DB::transaction(function () use ($redemption): void {
            if ($redemption->status === 'released') {
                return;
            }

            if ($redemption->points_used > 0) {
                $this->rewards->refundRedemption($redemption);
            }

            $redemption->offer->decrement('usage_count');
            $redemption->forceFill(['status' => 'released', 'discount_minor' => 0])->save();
        });
    }

    /**
     * Compute the discount for an offer against a subtotal.
     */
    public function calculateDiscount(Offer $offer, int $subtotalMinor): int
    {
        if ($offer->discount_type === \App\Enums\DiscountType::Percentage) {
            $discount = (int) floor($subtotalMinor * $offer->discount_value / 100);

            if ($offer->max_discount_minor !== null) {
                $discount = min($discount, (int) $offer->max_discount_minor);
            }
        } else {
            $discount = (int) $offer->discount_value;
        }

        return max(0, min($discount, $subtotalMinor));
    }

    /**
     * Throw unless the user may use this offer right now on the given service/subtotal.
     */
    public function assertEligible(User $user, Offer $offer, ?Service $service = null, ?int $subtotalMinor = null): void
    {
        $now = now();

        if ($offer->status === OfferStatus::Expired || ($offer->expires_at !== null && $offer->expires_at->isPast())) {
            throw ApiException::conflict(ErrorCode::OfferUnavailable, 'This offer has expired.');
        }

        if ($offer->status !== OfferStatus::Active || $offer->starts_at->isFuture()) {
            throw ApiException::conflict(ErrorCode::OfferUnavailable, 'This offer is not active yet.');
        }

        if ($subtotalMinor !== null && $subtotalMinor < (int) $offer->minimum_booking_amount_minor) {
            throw ApiException::conflict(ErrorCode::OfferUnavailable, 'The booking amount does not meet the offer minimum.');
        }

        if ($service !== null && $offer->service_id !== null && $offer->service_id !== $service->getKey()) {
            throw ApiException::conflict(ErrorCode::OfferUnavailable, 'This offer does not apply to the selected service.');
        }

        $usedByUser = OfferRedemption::query()
            ->where('offer_id', $offer->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', '!=', 'released')
            ->count();

        if ($usedByUser >= (int) $offer->per_user_limit) {
            throw ApiException::conflict(ErrorCode::OfferAlreadyRedeemed, 'You have already used this offer.');
        }

        if ($offer->total_usage_limit !== null) {
            $usedTotal = OfferRedemption::query()
                ->where('offer_id', $offer->getKey())
                ->where('status', '!=', 'released')
                ->count();

            if ($usedTotal >= (int) $offer->total_usage_limit) {
                throw ApiException::conflict(ErrorCode::OfferUnavailable, 'This offer has reached its usage limit.');
            }
        }

        if ($offer->requires_reward_points && $offer->reward_points_cost > 0) {
            $balance = (int) $user->reward_points;

            if ($balance < (int) $offer->reward_points_cost) {
                throw new ApiException(ErrorCode::RewardPointsInsufficient, 'Not enough reward points for this offer.');
            }
        }
    }
}
