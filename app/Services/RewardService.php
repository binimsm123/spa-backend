<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\RewardTransactionType;
use App\Models\Booking;
use App\Models\OfferRedemption;
use App\Models\RewardConfiguration;
use App\Models\RewardPointTransaction;
use App\Models\User;
use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

class RewardService
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Active configuration, if any.
     */
    public function activeConfiguration(): ?RewardConfiguration
    {
        return RewardConfiguration::query()
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Award points for a paid booking according to the active configuration.
     */
    public function awardForBooking(Booking $booking): ?RewardPointTransaction
    {
        if ($booking->reward_points_earned > 0) {
            return null; // already awarded
        }

        $config = $this->activeConfiguration();

        if ($config === null) {
            return null;
        }

        $basisAmount = match ($config->basis) {
            'subtotal' => $booking->subtotal_minor - $booking->discount_minor,
            'post_discount' => $booking->subtotal_minor - $booking->discount_minor,
            'tax_inclusive' => $booking->subtotal_minor - $booking->discount_minor + $booking->tax_minor,
            'total' => $booking->total_minor,
            default => $booking->subtotal_minor,
        };

        // Money is in minor units; convert to major units for the ratio.
        $points = (int) floor(($basisAmount / 100) * (float) $config->points_per_rupee);

        if ($points <= 0) {
            return null;
        }

        $transaction = $this->applyTransaction(
            $booking->user,
            RewardTransactionType::Earned,
            $points,
            "Earned from booking {$booking->getKey()}",
            booking: $booking,
            configuration: $config,
        );

        // Stamp the booking so awards are idempotent and reportable.
        $booking->forceFill(['reward_points_earned' => $points])->save();

        return $transaction;
    }

    /**
     * Spend points on an offer redemption inside the caller's transaction.
     */
    public function spendPoints(User $user, int $points, OfferRedemption $redemption, string $description = 'Offer redemption'): RewardPointTransaction
    {
        return DB::transaction(function () use ($user, $points, $redemption, $description) {
            $balance = $this->lockedBalance($user);

            if ($balance < $points) {
                throw new ApiException(ErrorCode::RewardPointsInsufficient, 'Not enough reward points.');
            }

            return $this->applyTransaction(
                $user,
                RewardTransactionType::Redeemed,
                -$points,
                $description,
                redemption: $redemption,
                expectedBalance: $balance,
            );
        });
    }

    /**
     * Refund points when an applied redemption is released (e.g. booking cancelled).
     */
    public function refundRedemption(OfferRedemption $redemption, string $description = 'Redemption refunded'): void
    {
        if ($redemption->points_used <= 0) {
            return;
        }

        $this->applyTransaction(
            $redemption->user,
            RewardTransactionType::Refunded,
            (int) $redemption->points_used,
            $description,
            redemption: $redemption,
        );
    }

    /**
     * Controlled administrative adjustment with a mandatory reason.
     */
    public function adjust(User $user, int $delta, string $reason, User $actor): RewardPointTransaction
    {
        return $this->applyTransaction($user, RewardTransactionType::Adjusted, $delta, $reason);
    }

    /**
     * Apply a ledger transaction and update the cached user balance in the same transaction.
     */
    private function applyTransaction(
        User $user,
        RewardTransactionType $type,
        int $points,
        string $description,
        ?Booking $booking = null,
        ?OfferRedemption $redemption = null,
        ?RewardConfiguration $configuration = null,
        ?int $expectedBalance = null,
    ): RewardPointTransaction {
        return DB::transaction(function () use ($user, $type, $points, $description, $booking, $redemption, $configuration, $expectedBalance) {
            $balance = $expectedBalance ?? $this->lockedBalance($user);

            /** @var RewardPointTransaction $transaction */
            $transaction = RewardPointTransaction::query()->create([
                'user_id' => $user->getKey(),
                'booking_id' => $booking?->getKey(),
                'offer_redemption_id' => $redemption?->getKey(),
                'reward_configuration_id' => $configuration?->getKey(),
                'type' => $type->value,
                'points' => $points,
                'balance_after' => $balance + $points,
                'description' => $description,
                'created_at' => now(),
            ]);

            $user->addRewardPoints($points);

            return $transaction;
        });
    }

    /**
     * Lock the user row and return the cached balance as an integer.
     */
    private function lockedBalance(User $user): int
    {
        $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

        return (int) ($fresh->reward_points ?? 0);
    }
}
