<?php

namespace App\Enums;

enum WaOutboundMessageStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
