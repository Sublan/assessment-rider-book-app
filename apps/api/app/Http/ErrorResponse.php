<?php

declare(strict_types=1);

namespace App\Http;

use App\Enums\ApiErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One error envelope for the whole API:
 *
 *   { "error": { "code": "...", "message": "...", "details": {...}, "requestId": "..." } }
 *
 * Clients branch on `code` (stable) and show `message` (human readable); the
 * request id lets a user paste one string into a bug report.
 */
final class ErrorResponse
{
    /** @param array<string, mixed>|null $details */
    public static function make(
        ApiErrorCode $code,
        string $message,
        ?array $details = null,
        ?Request $request = null,
    ): JsonResponse {
        $requestId = $request?->attributes->get('request_id');

        return response()->json(
            array_filter([
                'error' => array_filter([
                    'code' => $code->value,
                    'message' => $message,
                    'details' => $details,
                    'requestId' => is_string($requestId) ? $requestId : null,
                ], static fn (mixed $value): bool => $value !== null),
            ]),
            $code->status(),
        );
    }
}
