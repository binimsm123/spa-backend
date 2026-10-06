<?php

namespace App\Enums;

enum ReferralRewardStatus: string
{
    case Pending = 'pending';
    case Available = 'available';
    case Redeemed = 'redeemed';
    case Expired = 'expired';
}
