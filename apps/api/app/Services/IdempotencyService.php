<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Exceptions\ReplayResponse;
use App\Models\IdempotencyKey;

/**
 * Enforces exactly-once semantics for non-safe ride mutations.
 *
 * Pattern:
 *   1. Before starting the work, try to insert (key, scope, request_hash)
 *      atomically.
 *   2. If the insert succeeds, we own the key for this attempt; run the work.
 *   3. If the insert hit a unique constraint:
 *      a. If request_hash doesn't match: the client reused a key for different
 *         parameters. Reject with 422 IDEMPOTENCY_KEY_REUSED.
 *      b. If response_code is null: another request with the same key is still
 *         executing. Reject with 409 IDEMPOTENCY_IN_PROGRESS.
 *      c. If response_code is set: replay the stored response byte-for-byte with
 *         an `Idempotent-Replayed: true` header.
 *
 * Scope isolates keys between riders: Alice and Bob using the same UUID
 * as an idempotency key will not collide with each other.
 */
final class IdempotencyService
{
    public function hash(mixed $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function fingerprint(mixed $payload): string
    {
        return $this->hash($payload);
    }

    /**
     * Fast path: if this key already produced a response, return it.
     *
     * @return array{status: int, body: array<string, mixed>}|null
     */
    public function replay(string $key, string $scope, string $requestHash): ?array
    {
        $existing = IdempotencyKey::query()
            ->where('key', $key)
            ->where('scope', $scope)
            ->first();

        return $existing === null ? null : $this->replayFrom($existing, $requestHash);
    }

    /**
     * Claim the key for this transaction, or bail out with the previous
     * response / a conflict. Must be called inside a transaction.
     */
    public function claim(string $key, string $scope, string $requestHash): void
    {
        // Eloquent insertOrIgnore handles 'INSERT IGNORE' on MySQL and 'ON CONFLICT DO NOTHING' on Postgres
        $inserted = IdempotencyKey::query()->insertOrIgnore([
            'key'          => $key,
            'scope'        => $scope,
            'request_hash' => $requestHash,
            'created_at'   => now(),
        ]);

        if ($inserted > 0) {
            // Freshly claimed key for this request execution
            return;
        }

        // Key already exists — fetch and lock for inspection
        $existing = IdempotencyKey::query()
            ->where('key', $key)
            ->where('scope', $scope)
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            // The row vanished between the insert and the select (pruned mid-flight).
            throw new ApiException(ApiErrorCode::IdempotencyInProgress, 'Retry this request');
        }

        $replayed = $this->replayFrom($existing, $requestHash);

        if ($replayed !== null) {
            // A retry of an already-completed request. Returning here would skip
            // the caller's write, so signal it with a dedicated exception the
            // caller turns back into the stored response.
            throw new ReplayResponse($replayed['status'], $replayed['body']);
        }
    }

    /** @param array<string, mixed> $body */
    public function remember(string $key, string $scope, int $status, array $body, ?string $resourceId): void
    {
        IdempotencyKey::query()
            ->where('key', $key)
            ->where('scope', $scope)
            ->update([
                'response_code' => $status,
                'response_body' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'resource_id' => $resourceId,
            ]);
    }

    /** @param object $row */
    private function replayFrom(object $row, string $requestHash): ?array
    {
        if (! hash_equals((string) $row->request_hash, $requestHash)) {
            throw ApiException::idempotencyKeyReused();
        }

        if ($row->response_code === null) {
            throw ApiException::idempotencyInProgress();
        }

        return [
            'status' => (int) $row->response_code,
            'body' => is_array($row->response_body) ? $row->response_body : (json_decode((string) $row->response_body, true) ?? []),
        ];
    }
}
