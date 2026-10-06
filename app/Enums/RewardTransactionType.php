<?php

namespace App\Enums;

enum RewardTransactionType: string
{
    case Earned = 'earned';
    case Redeemed = 'redeemed';
    case Refunded = 'refunded';
    case Expired = 'expired';
    case Adjusted = 'adjusted';
}
