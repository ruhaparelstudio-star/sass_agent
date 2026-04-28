<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}

