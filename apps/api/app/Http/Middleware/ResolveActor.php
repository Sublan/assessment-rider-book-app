<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Rider;
use App\Support\ActorContext;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves "who is calling" from a Sanctum Bearer token.
 *
 * The token encodes the actor type via its abilities:
 *   - `role:rider`  → the tokenable model is a Rider
 *   - `role:driver` → the tokenable model is a Driver
 *
 * If no Authorization header is present the request is treated as anonymous
 * (public endpoints still work; protected controllers check ActorContext and
 * throw 401 themselves if the actor is missing).
 */
final class ResolveActor
{
    public function __construct(private readonly ActorContext $actor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->actor->setRiderId(null);
        $this->actor->setDriverId(null);

        $bearerToken = $request->bearerToken();

        if ($bearerToken !== null && $bearerToken !== '') {
            $this->resolveFromToken($request, $bearerToken);
        }

        // Keep backwards compatibility for test cases or services passing explicit headers
        if ($this->actor->riderId() === null && ($riderHeader = $request->header('x-rider-id'))) {
            if (\Ramsey\Uuid\Uuid::isValid($riderHeader)) {
                $this->actor->setRiderId($riderHeader);
            } else {
                throw ApiException::validation('The x-rider-id header must be a UUID');
            }
        }

        if ($this->actor->driverId() === null && ($driverHeader = $request->header('x-driver-id'))) {
            if (\Ramsey\Uuid\Uuid::isValid($driverHeader)) {
                $this->actor->setDriverId($driverHeader);
            } else {
                throw ApiException::validation('The x-driver-id header must be a UUID');
            }
        }

        return $next($request);
    }

    private function resolveFromToken(Request $request, string $rawToken): void
    {
        // Sanctum plain text tokens are formatted as "{id}|{hash}"
        $pat = PersonalAccessToken::findToken($rawToken);

        if ($pat === null) {
            // If the route is a public directory/locations route, ignore stale token rather than hard failing
            if ($request->is('api/riders', 'api/drivers', 'api/locations', 'api/health', 'api/rides/estimate')) {
                return;
            }
            throw ApiException::unauthorized('Invalid or expired token. Please log in again.');
        }

        $tokenable = $pat->tokenable;

        if ($tokenable instanceof Rider) {
            if (! $pat->can('role:rider')) {
                throw ApiException::unauthorized('Token does not have rider permissions.');
            }
            $this->actor->setRiderId($tokenable->id);
            return;
        }

        if ($tokenable instanceof Driver) {
            if (! $pat->can('role:driver')) {
                throw ApiException::unauthorized('Token does not have driver permissions.');
            }
            $this->actor->setDriverId($tokenable->id);
            return;
        }

        throw ApiException::unauthorized('Unrecognised token type.');
    }
}
