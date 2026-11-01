<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Control-flow signal carrying an already-produced response for a retried
 * request. Thrown out of IdempotencyService::claim (which must abort the
 * caller's write) and caught by the same transaction wrapper that claimed it.
 */
final class ReplayResponse extends \RuntimeException
{
    /** @param array<string, mixed> $body */
    public function __construct(public readonly int $status, public readonly array $body)
    {
        parent::__construct('Idempotent replay');
    }
}
