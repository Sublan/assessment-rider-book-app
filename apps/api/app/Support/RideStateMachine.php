<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\RideStatus;
use App\Exceptions\ApiException;

final class RideStateMachine
{
    /**
     * Rejects a transition the lifecycle does not allow. Called by every service
     * method that mutates a ride, after re-reading the row under a lock.
     */
    public static function assertCanTransition(RideStatus $from, RideStatus $to): void
    {
        if (! $from->canTransitionTo($to)) {
            throw ApiException::invalidTransition($from->value, self::actionFor($to));
        }
    }

    private static function actionFor(RideStatus $target): string
    {
        return match ($target) {
            RideStatus::Accepted => 'accept',
            RideStatus::Cancelled => 'cancel',
            RideStatus::Completed => 'complete',
            RideStatus::Requested => 'reopen',
        };
    }
}
