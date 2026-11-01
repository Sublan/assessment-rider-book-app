<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The ride lifecycle, in one place.
 *
 * `requested -> accepted -> completed` is the happy path; `requested` and
 * `accepted` may both be cancelled. Terminal states stay terminal: every other
 * transition is rejected by RideStateMachine with 409 INVALID_STATE_TRANSITION.
 */
enum RideStatus: string
{
    case Requested = 'requested';
    case Accepted = 'accepted';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Accepted, self::Cancelled],
            self::Accepted => [self::Cancelled, self::Completed],
            self::Cancelled, self::Completed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function isActive(): bool
    {
        return $this === self::Requested || $this === self::Accepted;
    }

    public function isCancellable(): bool
    {
        return $this->canTransitionTo(self::Cancelled);
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return [self::Requested->value, self::Accepted->value];
    }
}
