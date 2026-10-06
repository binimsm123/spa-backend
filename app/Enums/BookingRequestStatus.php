<?php

namespace App\Enums;

enum BookingRequestStatus: string
{
    case Pending = 'pending';
    case TimesProposed = 'times_proposed';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Confirmed = 'confirmed';
}
