<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every failure the API can produce has a stable machine-readable code and a
 * fixed HTTP status, so clients never have to parse prose.
 */
enum ApiErrorCode: string
{
    case ValidationError = 'VALIDATION_ERROR';
    case Unauthorized = 'UNAUTHORIZED';
    case MissingActor = 'MISSING_ACTOR';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case RideNotFound = 'RIDE_NOT_FOUND';
    case RiderNotFound = 'RIDER_NOT_FOUND';
    case DriverNotFound = 'DRIVER_NOT_FOUND';
    case ActiveRideExists = 'ACTIVE_RIDE_EXISTS';
    case RideAlreadyAccepted = 'RIDE_ALREADY_ACCEPTED';
    case InvalidStateTransition = 'INVALID_STATE_TRANSITION';
    case DriverNotEligible = 'DRIVER_NOT_ELIGIBLE';
    case DriverBusy = 'DRIVER_BUSY';
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';
    case IdempotencyInProgress = 'IDEMPOTENCY_IN_PROGRESS';
    case OutsideServiceArea = 'OUTSIDE_SERVICE_AREA';
    case TripTooShort = 'TRIP_TOO_SHORT';
    case TripTooLong = 'TRIP_TOO_LONG';
    case RateLimited = 'RATE_LIMITED';
    case InternalError = 'INTERNAL_ERROR';
    case ServiceUnavailable = 'SERVICE_UNAVAILABLE';

    public function status(): int
    {
        return match ($this) {
            self::ValidationError, self::IdempotencyKeyReused,
            self::OutsideServiceArea, self::TripTooShort, self::TripTooLong => 422,
            self::Unauthorized, self::MissingActor => 401,
            self::Forbidden => 403,
            self::NotFound, self::RideNotFound, self::RiderNotFound, self::DriverNotFound => 404,
            self::ActiveRideExists, self::RideAlreadyAccepted, self::InvalidStateTransition,
            self::DriverNotEligible, self::DriverBusy, self::IdempotencyInProgress => 409,
            self::RateLimited => 429,
            self::ServiceUnavailable => 503,
            self::InternalError => 500,
        };
    }
}
