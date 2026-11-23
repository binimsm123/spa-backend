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
            $offer = Offer::query()->lockForUpdate()->findOrFail($offer->getKey());
            $this->assertEligible($user, $offer, $service, lockUsage: true);

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

            $this->spendPoints($user, $offer, $redemption);

            return $redemption;
        });
    }

    public function reservationForQuote(User $user, Offer $offer, Booking $booking, bool $lock = false): ?OfferRedemption
    {
        return $offer->redemptions()
            ->where('user_id', $user->getKey())
            ->where('status', 'reserved')
            ->where(function ($query) use ($booking): void {
                $query->where('booking_id', $booking->getKey())
                    ->orWhere(fn ($unbound) => $unbound->whereNull('booking_id')
                        ->where('service_id', $booking->items->first()?->service_id)
                        ->where('expires_at', '>', now()));
            })
            ->orderByDesc('booking_id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    // The caller holds the booking lock and the payment transaction.
    public function reserveForCheckout(User $user, Booking $booking, CheckoutQuote $quote): void
    {
        $existing = $booking->offerReservation()->first();
        if ($existing !== null) {
            if ($existing->offer_id !== $quote->offer_id) {
                throw ApiException::conflict(ErrorCode::QuoteInvalid, 'Retry payment with the same offer already reserved for this booking.');
            }

            $existing->update(['discount_minor' => $quote->discount_minor]);

            return;
        }

        if ($quote->offer_id === null && $quote->discount_minor === 0) {
            return;
        }

        $offer = $quote->offer()->lockForUpdate()->first();
        if ($offer === null) {
            throw ApiException::conflict(ErrorCode::OfferUnavailable, 'This offer is no longer available.');
        }

        $reservation = $this->reservationForQuote($user, $offer, $booking, lock: true);
        $this->assertEligible($user, $offer, $booking->items->first()?->service, $quote->subtotal_minor, $reservation, lockUsage: true);
        $redemption = $reservation ?? new OfferRedemption([
            'offer_id' => $offer->getKey(),
            'user_id' => $user->getKey(),
            'service_id' => $booking->items->first()?->service_id,
            'idempotency_key' => (string) Str::ulid(),
            'status' => 'reserved',
        ]);
        $redemption->fill([
            'booking_id' => $booking->getKey(),
            'discount_minor' => $quote->discount_minor,
            'expires_at' => null,
        ])->save();

        if ($reservation === null) {
            $this->spendPoints($user, $offer, $redemption);
        }
    }

    public function confirmReservation(Booking $booking): void
    {
        $redemption = $booking->offerReservation()->first();
        if ($redemption !== null) {
            Offer::withTrashed()->whereKey($redemption->offer_id)->increment('usage_count');
            $redemption->update(['status' => 'applied', 'redeemed_at' => now()]);
        }
    }

    public function release(OfferRedemption $redemption): void
    {
        DB::transaction(function () use ($redemption): void {
            $offer = Offer::withTrashed()->lockForUpdate()->findOrFail($redemption->offer_id);
            $redemption = OfferRedemption::query()->lockForUpdate()->findOrFail($redemption->getKey());
            if ($redemption->status === 'released') {
                return;
            }

            if ($redemption->points_used > 0) {
                $this->rewards->refundRedemption($redemption);
            }

            if ($redemption->status === 'applied' && $offer->usage_count > 0) {
                $offer->decrement('usage_count');
            }

            $redemption->update(['status' => 'released', 'discount_minor' => 0]);
        });
    }

    private function spendPoints(User $user, Offer $offer, OfferRedemption $redemption): void
    {
        if ($offer->requires_reward_points && $offer->reward_points_cost > 0) {
            $this->rewards->spendPoints($user, (int) $offer->reward_points_cost, $redemption, 'Redeemed offer '.$offer->code);
            $redemption->update(['points_used' => $offer->reward_points_cost]);
        }
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
    public function assertEligible(User $user, Offer $offer, ?Service $service = null, ?int $subtotalMinor = null, ?OfferRedemption $reservation = null, bool $lockUsage = false): void
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

        if ($offer->business_id !== null && $offer->business_id !== $service?->business_id) {
            throw ApiException::conflict(ErrorCode::OfferUnavailable, 'This offer does not apply to the selected business.');
        }

        if ($offer->service_id !== null && $offer->service_id !== $service?->getKey()) {
            throw ApiException::conflict(ErrorCode::OfferUnavailable, 'This offer does not apply to the selected service.');
        }

        $usage = $offer->redemptions()
            ->where(fn ($query) => $query->where('status', 'applied')
                ->orWhere(fn ($pending) => $pending->where('status', 'reserved')
                    ->where(fn ($active) => $active->whereNull('expires_at')->orWhere('expires_at', '>', $now))))
            ->when($reservation, fn ($query) => $query->whereKeyNot($reservation->getKey()));

        $personalUsage = (clone $usage)->where('user_id', $user->getKey());
        $usedByUser = $lockUsage ? $personalUsage->sharedLock()->pluck('id')->count() : $personalUsage->count();
        if ($usedByUser >= (int) $offer->per_user_limit) {
            throw ApiException::conflict(ErrorCode::OfferAlreadyRedeemed, 'You have reached your personal usage limit for this offer.');
        }

        if ($offer->total_usage_limit !== null) {
            $usedTotal = $lockUsage ? $usage->sharedLock()->pluck('id')->count() : $usage->count();

            if ($usedTotal >= (int) $offer->total_usage_limit) {
                throw ApiException::conflict(ErrorCode::OfferUnavailable, 'This offer has reached its usage limit.');
            }
        }

        if ($reservation === null && $offer->requires_reward_points && $offer->reward_points_cost > 0) {
            $balance = (int) $user->reward_points;

            if ($balance < (int) $offer->reward_points_cost) {
                throw new ApiException(ErrorCode::RewardPointsInsufficient, 'Not enough reward points for this offer.');
            }
        }
    }
}
