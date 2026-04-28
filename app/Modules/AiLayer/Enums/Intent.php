<?php

namespace App\Modules\AiLayer\Enums;

enum Intent: string
{
    case Greeting = 'greeting';
    case AskPackage = 'ask_package';
    case AskPrice = 'ask_price';
    case AskAvailability = 'ask_availability';
    case BookingIntent = 'booking_intent';
    case Unknown = 'unknown';

    public static function fromNullableString(?string $value): self
    {
        if (! is_string($value) || $value === '') {
            return self::Unknown;
        }

        return self::tryFrom($value) ?? self::Unknown;
    }
}
