<?php

namespace App\Modules\AiLayer\Enums;

enum Intent: string
{
    case Greeting = 'greeting';
    case FirstContact = 'first_contact';
    case IntroInterest = 'intro_interest';
    case AskPackage = 'ask_package';
    case AskPackageDetail = 'ask_package_detail';
    case AskPrice = 'ask_price';
    case AskPricelist = 'ask_pricelist';
    case AskAvailability = 'ask_availability';
    case BookingIntent = 'booking_intent';
    case RequestHandoff = 'request_handoff';
    case Complaint = 'complaint';
    case PaymentRelated = 'payment_related';
    case TopicSwitch = 'topic_switch';
    case Correction = 'correction';
    case UnclearMessage = 'unclear_message';
    case Unknown = 'unknown';

    public static function fromNullableString(?string $value): self
    {
        if (! is_string($value) || $value === '') {
            return self::Unknown;
        }

        $normalized = strtolower(trim($value));
        $aliases = [
            'ask_package_list' => self::AskPackage,
            'ask_package_comparison' => self::AskPackageDetail,
            'ask_booking_flow' => self::BookingIntent,
            'request_admin' => self::RequestHandoff,
        ];

        if (array_key_exists($normalized, $aliases)) {
            return $aliases[$normalized];
        }

        return self::tryFrom($normalized) ?? self::Unknown;
    }
}
