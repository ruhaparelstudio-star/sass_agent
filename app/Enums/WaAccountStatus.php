<?php

namespace App\Enums;

enum WaAccountStatus: string
{
    case Disconnected = 'disconnected';
    case Connecting = 'connecting';
    case Connected = 'connected';
}
