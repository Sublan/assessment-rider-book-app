<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DriverStatus;
use App\Http\Presenters\DriverPresenter;
use App\Http\Requests\DriverStatusRequest;
use App\Models\Rider;
use App\Services\DriverService;
use App\Support\ActorContext;
use App\Support\LahoreLocations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Demo support endpoints. They exist so the SPA can offer a driver/rider picker
 * without a login screen; a production build would replace them with
 * authenticated profile endpoints.
 */
final class DirectoryController extends Controller
{
    public function __construct(
        private readonly DriverService $drivers,
        private readonly ActorContext $actor,
    ) {}

    public function locations(): JsonResponse
    {
        return response()->json([
            'data' => [
                'city' => config('ride.default_city'),
                'bounds' => LahoreLocations::BOUNDS,
                'locations' => LahoreLocations::all(),
            ],
        ]);
    }

    public function riders(): JsonResponse
    {
        $riders = Rider::query()->orderBy('full_name')->get()->map(static fn (Rider $rider) => [
            'id' => $rider->id,
            'fullName' => $rider->full_name,
            'phone' => $rider->phone,
        ]);

        return response()->json(['data' => $riders]);
    }

    public function drivers(Request $request): JsonResponse
    {
        $status = $request->query('status');

        return response()->json([
            'data' => DriverPresenter::collection($this->drivers->all(is_string($status) ? $status : null)),
        ]);
    }

    public function updateDriverStatus(DriverStatusRequest $request, string $driver): JsonResponse
    {
        $validated = $request->validated();

        $updated = $this->drivers->setStatus(
            $driver,
            $this->actor->requireDriverId(),
            DriverStatus::from($validated['status']),
            isset($validated['lat']) ? (float) $validated['lat'] : null,
            isset($validated['lng']) ? (float) $validated['lng'] : null,
        );

        return response()->json(['data' => DriverPresenter::present($updated)]);
    }

    public function health(): JsonResponse
    {
        return response()->json([
            'data' => [
                'status' => 'ok',
                'service' => 'ridebook-api',
                'time' => now()->toIso8601String(),
            ],
        ]);
    }
}
