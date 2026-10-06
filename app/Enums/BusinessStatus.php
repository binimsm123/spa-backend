<?php

namespace App\Enums;

enum BusinessStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
}
