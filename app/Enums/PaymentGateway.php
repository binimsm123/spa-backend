<?php

namespace App\Enums;

enum PaymentGateway: string
{
    case ESewa = 'esewa';
    case Khalti = 'khalti';
    case MyPay = 'mypay';
}
