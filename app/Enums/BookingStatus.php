<?php

namespace App\Enums;

enum BookingStatus: string
{
    case AwaitingPayment = 'awaiting_payment';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Refunded = 'refunded';

    public function isCompleted(): bool
    {
        return $this === self::Completed;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Expired, self::Refunded], true);
    }

    /**
     * Allowed transitions enforced by BookingService.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::AwaitingPayment => in_array($target, [self::Confirmed, self::Cancelled, self::Expired], true),
            self::Confirmed => in_array($target, [self::InProgress, self::Completed, self::Cancelled, self::Refunded], true),
            self::InProgress => in_array($target, [self::Completed, self::Cancelled], true),
            self::Completed, self::Cancelled, self::Expired => $target === self::Refunded,
            self::Refunded => false,
        };
    }
}
