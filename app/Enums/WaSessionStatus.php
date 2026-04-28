<?php

namespace App\Enums;

enum WaSessionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Closed = 'closed';
}
