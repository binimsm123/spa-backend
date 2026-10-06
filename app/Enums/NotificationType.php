<?php

namespace App\Enums;

enum NotificationType: string
{
    case Booking = 'booking';
    case Payment = 'payment';
    case Offer = 'offer';
    case Referral = 'referral';
    case Reward = 'reward';
    case Account = 'account';
    case Announcement = 'announcement';
    case StaffInvite = 'staff_invite';
}
