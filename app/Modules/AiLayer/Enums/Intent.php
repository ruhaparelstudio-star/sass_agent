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
    case ProvideName = 'provide_name';
    case ProvideDate = 'provide_date';
    case ProvideEventType = 'provide_event_type';
    case ProvideBudget = 'provide_budget';
    case ProvidePreference = 'provide_preference';
    case BookingIntent = 'booking_intent';
    case ConfirmBooking = 'confirm_booking';
    case AskFaq = 'ask_faq';
    case ObjectionPrice = 'objection_price';
    case ObjectionTime = 'objection_time';
    case ObjectionMisc = 'objection_misc';
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
            'package_detail' => self::AskPackageDetail,
            'ask_package_comparison' => self::AskPackageDetail,
            'ask_availability_date' => self::AskAvailability,
            'availability' => self::AskAvailability,
            'provide_customer_name' => self::ProvideName,
            'provide_event_date' => self::ProvideDate,
            'provide_type' => self::ProvideEventType,
            'provide_budget_range' => self::ProvideBudget,
            'provide_needs' => self::ProvidePreference,
            'ask_booking_flow' => self::BookingIntent,
            'ask_booking_link' => self::BookingIntent,
            'ask_lock_date' => self::BookingIntent,
            'confirm_book' => self::ConfirmBooking,
            'booking_confirm' => self::ConfirmBooking,
            'faq' => self::AskFaq,
            'ask_invoice' => self::PaymentRelated,
            'objection' => self::ObjectionMisc,
            'objection_pricing' => self::ObjectionPrice,
            'objection_schedule' => self::ObjectionTime,
            'request_admin' => self::RequestHandoff,
            'handoff' => self::RequestHandoff,
            'payment' => self::PaymentRelated,
            'topic_change' => self::TopicSwitch,
            'revise' => self::Correction,
            'unclear' => self::UnclearMessage,
        ];

        if (array_key_exists($normalized, $aliases)) {
            return $aliases[$normalized];
        }

        return self::tryFrom($normalized) ?? self::Unknown;
    }
}
