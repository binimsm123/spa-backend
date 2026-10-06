<?php

namespace App\Enums;

enum VerificationType: string
{
    case Registration = 'registration';
    case MobileChange = 'mobile_change';
    case PasswordReset = 'password_reset';
}
