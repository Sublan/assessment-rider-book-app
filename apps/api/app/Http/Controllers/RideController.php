<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Presenters\RidePresenter;
use App\Http\Requests\CancelRideRequest;
use App\Http\Requests\CreateRideRequest;
use App\Http\Requests\EstimateRequest;
use App\Enums\RideCategory;
use App\Models\Ride;
use App\Services\RideService;
use App\Services\TripPlanner;
use App\Support\ActorContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin HTTP layer: validate, delegate to a service, present.
 *
 * No business rule lives here — that is what keeps the lifecycle rules testable
 * without HTTP and keeps the API contract stable when the transport changes.
 */
final class RideController extends Controller
{
    public function __construct(
        private readonly RideService $rides,
        private readonly TripPlanner $planner,
        private readonly ActorContext $actor,
    ) {}

    public function store(CreateRideRequest $request): JsonResponse
    {
        $riderId = $this->actor->requireRiderId();
        $idempotencyKey = $this->idempotencyKey($request);

        $result = $this->rides->create($request->validated(), $riderId, $idempotencyKey);

        return response()
            ->json($result['body'], $result['status'], [
                'Idempotent-Replayed' => $result['replayed'] ? 'true' : 'false',
            ]);
    }

    public function show(string $ride): JsonResponse
    {
        return response()->json(['data' => RidePresenter::present($this->rides->find($ride))]);
    }

    public function accept(string $ride): JsonResponse
    {
        $driverId = $this->actor->requireDriverId();
        $accepted = $this->rides->accept($ride, $driverId);

        return response()->json(['data' => RidePresenter::present($accepted)]);
    }

    public function cancel(CancelRideRequest $request, string $ride): JsonResponse
    {
        $riderId = $this->actor->riderId();
        $driverId = $this->actor->driverId();

        if ($riderId === null && $driverId === null) {
            throw ApiException::missingActor('x-rider-id or x-driver-id');
        }

        $cancelled = $this->rides->cancel($ride, $riderId, $driverId, $request->validated('reason'));

        return response()->json(['data' => RidePresenter::present($cancelled)]);
    }

    public function complete(string $ride): JsonResponse
    {
        $driverId = $this->actor->requireDriverId();
        $completed = $this->rides->complete($ride, $driverId);

        return response()->json(['data' => RidePresenter::present($completed)]);
    }

    public function estimate(EstimateRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $category = RideCategory::from($validated['category']);

        $plan = $this->planner->plan(
            $category,
            (float) $validated['pickup']['lat'],
            (float) $validated['pickup']['lng'],
            (float) $validated['destination']['lat'],
            (float) $validated['destination']['lng'],
            (string) config('ride.default_city'),
        );

        return response()->json([
            'data' => [
                'category' => $category->value,
                'currency' => $plan['fare']['currency'],
                'fare' => [
                    'totalMinor' => $plan['fare']['total_minor'],
                    'totalPkr' => round($plan['fare']['total_minor'] / 100, 2),
                    'breakdown' => [
                        'baseMinor' => $plan['fare']['base_minor'],
                        'distanceMinor' => $plan['fare']['distance_minor'],
                        'timeMinor' => $plan['fare']['time_minor'],
                        'minimumMinor' => $plan['fare']['minimum_minor'],
                        'surgeMultiplier' => $plan['fare']['surge_multiplier'],
                    ],
                ],
                'distanceMeters' => $plan['distanceMeters'],
                'durationSeconds' => $plan['durationSeconds'],
                'pickupEtaMinutes' => $plan['pickupEtaMinutes'],
                'availableDrivers' => $plan['availableDrivers'],
                'vehicleExample' => app(\App\Services\FareCalculator::class)->vehicleExample($category),
            ],
        ]);
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || $key === '') {
            return null;
        }

        if (strlen($key) < 8 || strlen($key) > 200) {
            throw ApiException::validation('The Idempotency-Key header must be between 8 and 200 characters', [
                'Idempotency-Key' => ['Length must be between 8 and 200 characters'],
            ]);
        }

        return $key;
    }
}
