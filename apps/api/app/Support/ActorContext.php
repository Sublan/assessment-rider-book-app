<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ApiException;

/**
 * Who is calling right now.
 *
 * ResolveActor middleware resolves the caller from a Sanctum Bearer token and
 * populates this context. Controllers and services only ever ask "who is the
 * rider/driver?". Ownership is still enforced on every ride mutation by
 * comparing this id with the ride's owner.
 */
final class ActorContext
{
    private ?string $riderId = null;

    private ?string $driverId = null;

    public function setRiderId(?string $riderId): void
    {
        $this->riderId = $riderId;
    }

    public function setDriverId(?string $driverId): void
    {
        $this->driverId = $driverId;
    }

    public function requireRiderId(): string
    {
        return $this->riderId ?? throw ApiException::missingActor('rider');
    }

    public function requireDriverId(): string
    {
        return $this->driverId ?? throw ApiException::missingActor('driver');
    }

    public function riderId(): ?string
    {
        return $this->riderId;
    }

    public function driverId(): ?string
    {
        return $this->driverId;
    }
}
