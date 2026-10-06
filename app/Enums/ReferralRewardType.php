<?php

namespace App\Enums;

enum ReferralRewardType: string
{
    case Points = 'points';
    case Credit = 'credit';
    case Discount = 'discount';
}
