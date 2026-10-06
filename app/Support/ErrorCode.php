<?php

namespace App\Support;

/**
 * Canonical error codes returned by the API envelope.
 */
enum ErrorCode: string
{
    case ValidationError = 'VALIDATION_ERROR';
    case Unauthenticated = 'UNAUTHENTICATED';
    case Unauthorized = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case Conflict = 'CONFLICT';
    case Throttled = 'THROTTLED';
    case ServerError = 'SERVER_ERROR';

    // Auth / account
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case EmailNotVerified = 'EMAIL_NOT_VERIFIED';
    case MobileNotVerified = 'MOBILE_NOT_VERIFIED';
    case AccountDisabled = 'ACCOUNT_DISABLED';
    case InvalidVerificationCode = 'INVALID_VERIFICATION_CODE';
    case VerificationCodeExpired = 'VERIFICATION_CODE_EXPIRED';
    case InvalidResetToken = 'INVALID_RESET_TOKEN';
    case WeakPassword = 'WEAK_PASSWORD';

    // Catalog / discovery
    case BusinessOffline = 'BUSINESS_OFFLINE';
    case BusinessClosed = 'BUSINESS_CLOSED';
    case LocationUnavailable = 'LOCATION_UNAVAILABLE';
    case ServiceUnavailable = 'SERVICE_UNAVAILABLE';
    case ServiceNotBookable = 'SERVICE_NOT_BOOKABLE';

    // Booking
    case BookingRequestExpired = 'BOOKING_REQUEST_EXPIRED';
    case BookingRequestNotAccepted = 'BOOKING_REQUEST_NOT_ACCEPTED';
    case BookingSlotConflict = 'BOOKING_SLOT_CONFLICT';
    case BookingStatusTransitionInvalid = 'BOOKING_STATUS_TRANSITION_INVALID';
    case BookingNotCompleted = 'BOOKING_NOT_COMPLETED';
    case CapacityExceeded = 'CAPACITY_EXCEEDED';
    case StaffNotAssigned = 'STAFF_NOT_ASSIGNED';
    case StaffDoubleBooked = 'STAFF_DOUBLE_BOOKED';

    // Commerce
    case OfferUnavailable = 'OFFER_UNAVAILABLE';
    case OfferAlreadyRedeemed = 'OFFER_ALREADY_REDEEMED';
    case OfferInvalidCode = 'OFFER_INVALID_CODE';
    case QuoteExpired = 'QUOTE_EXPIRED';
    case QuoteInvalid = 'QUOTE_INVALID';
    case PaymentFailed = 'PAYMENT_FAILED';
    case PaymentGatewayError = 'PAYMENT_GATEWAY_ERROR';
    case RewardPointsInsufficient = 'REWARD_POINTS_INSUFFICIENT';
    case ReviewAlreadySubmitted = 'REVIEW_ALREADY_SUBMITTED';
    case TipAmountInvalid = 'TIP_AMOUNT_INVALID';

    public function status(): int
    {
        return match ($this) {
            self::ValidationError => 422,
            self::Unauthenticated, self::InvalidCredentials, self::InvalidVerificationCode,
            self::VerificationCodeExpired, self::InvalidResetToken => 401,
            self::Unauthorized, self::EmailNotVerified, self::MobileNotVerified,
            self::AccountDisabled, self::StaffNotAssigned => 403,
            self::NotFound, self::LocationUnavailable, self::ServiceUnavailable => 404,
            self::Conflict, self::BookingSlotConflict, self::BookingRequestExpired,
            self::BookingRequestNotAccepted, self::BookingStatusTransitionInvalid,
            self::BookingNotCompleted, self::CapacityExceeded, self::StaffDoubleBooked,
            self::OfferUnavailable, self::OfferAlreadyRedeemed, self::OfferInvalidCode,
            self::QuoteExpired, self::QuoteInvalid, self::PaymentFailed,
            self::RewardPointsInsufficient, self::ReviewAlreadySubmitted,
            self::TipAmountInvalid, self::BusinessOffline, self::BusinessClosed,
            self::ServiceNotBookable => 409,
            self::Throttled => 429,
            self::WeakPassword, self::PaymentGatewayError, self::ServerError => 400,
        };
    }
}
