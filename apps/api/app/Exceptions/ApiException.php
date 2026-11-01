<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ApiErrorCode;
use RuntimeException;
use Throwable;

/**
 * Domain failures are thrown as this exception and rendered by the global
 * handler into `{ error: { code, message, details?, requestId } }`.
 *
 * Using one exception type (with a code enum) instead of a dozen subclasses
 * keeps the error contract obvious and testable, while services stay free of any
 * HTTP concerns.
 */
final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly ApiErrorCode $errorCode,
        string $message,
        public readonly ?array $details = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function status(): int
    {
        return $this->errorCode->status();
    }

    public static function rideNotFound(?string $id = null): self
    {
        return new self(ApiErrorCode::RideNotFound, $id ? "Ride {$id} does not exist" : 'Ride does not exist');
    }

    public static function riderNotFound(?string $id = null): self
    {
        return new self(ApiErrorCode::RiderNotFound, $id ? "Rider {$id} does not exist" : 'Rider does not exist');
    }

    public static function driverNotFound(?string $id = null): self
    {
        return new self(ApiErrorCode::DriverNotFound, $id ? "Driver {$id} does not exist" : 'Driver does not exist');
    }

    public static function forbidden(string $message = 'You are not allowed to perform this action'): self
    {
        return new self(ApiErrorCode::Forbidden, $message);
    }

    public static function missingActor(string $who): self
    {
        return new self(
            ApiErrorCode::MissingActor,
            "Authentication required: send a valid Bearer token for a {$who} account.",
        );
    }

    public static function unauthorized(string $message = 'Invalid or expired token. Please log in again.'): self
    {
        return new self(ApiErrorCode::Unauthorized, $message);
    }

    public static function invalidTransition(string $from, string $action): self
    {
        return new self(
            ApiErrorCode::InvalidStateTransition,
            "Cannot {$action} a ride that is {$from}",
            ['from' => $from, 'action' => $action],
        );
    }

    public static function rideAlreadyAccepted(?string $driverId = null): self
    {
        return new self(
            ApiErrorCode::RideAlreadyAccepted,
            'Another driver was assigned to this ride first',
            ['driverId' => $driverId],
        );
    }

    public static function activeRideExists(string $rideId): self
    {
        return new self(
            ApiErrorCode::ActiveRideExists,
            'You already have a ride in progress',
            ['rideId' => $rideId],
        );
    }

    public static function driverNotEligible(string $reason): self
    {
        return new self(ApiErrorCode::DriverNotEligible, $reason, ['reason' => $reason]);
    }

    public static function driverBusy(): self
    {
        return new self(ApiErrorCode::DriverBusy, 'This driver is already on another trip');
    }

    public static function idempotencyKeyReused(): self
    {
        return new self(
            ApiErrorCode::IdempotencyKeyReused,
            'This Idempotency-Key was already used with a different request body',
        );
    }

    public static function idempotencyInProgress(): self
    {
        return new self(
            ApiErrorCode::IdempotencyInProgress,
            'A request with this Idempotency-Key is still being processed',
        );
    }

    public static function validation(string $message, ?array $details = null): self
    {
        return new self(ApiErrorCode::ValidationError, $message, $details);
    }

    public static function outsideServiceArea(float $lat, float $lng): self
    {
        return new self(
            ApiErrorCode::OutsideServiceArea,
            sprintf('We only serve Lahore right now; (%.4f, %.4f) is outside our service area', $lat, $lng),
            ['lat' => $lat, 'lng' => $lng],
        );
    }

    public static function tripTooShort(): self
    {
        return new self(ApiErrorCode::TripTooShort, 'Pickup and destination are too close together');
    }

    public static function tripTooLong(): self
    {
        return new self(ApiErrorCode::TripTooLong, 'This trip is outside our supported range');
    }
}
