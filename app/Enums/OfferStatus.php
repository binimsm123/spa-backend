<?php

namespace App\Enums;

enum OfferStatus: string
{
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Paused = 'paused';
    case Expired = 'expired';
}
