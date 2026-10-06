<?php

namespace App\Enums;

enum ReferralStatus: string
{
    case Pending = 'pending';
    case Qualified = 'qualified';
    case Rewarded = 'rewarded';
    case Expired = 'expired';
}
