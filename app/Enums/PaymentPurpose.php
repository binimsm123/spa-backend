<?php

namespace App\Enums;

enum PaymentPurpose: string
{
    case Booking = 'booking';
    case Tip = 'tip';
}
