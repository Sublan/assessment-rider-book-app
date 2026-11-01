<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DriverStatus;
use App\Enums\RideStatus;
use App\Exceptions\ApiException;
use App\Http\Presenters\DriverPresenter;
use App\Http\Presenters\RidePresenter;
use App\Models\Driver;
use App\Models\Ride;
use App\Models\Rider;
use App\Support\ActorContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController extends Controller
{
    public function __construct(
        private readonly ActorContext $actor,
    ) {}

    /**
     * Rider login — phone lookup, issue a Sanctum token with ability 'role:rider'.
     * Returns the token so the frontend can store it in localStorage and send it
     * as `Authorization: Bearer <token>` on all subsequent requests.
     */
    public function riderLogin(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
        ], [
            'phone.regex' => 'The phone number format is invalid. Must be an international or standard phone number (e.g. +923001234501).',
        ]);

        $rider = Rider::query()
            ->where('phone', $request->string('phone')->toString())
            ->first();

        if ($rider === null) {
            throw ApiException::validation('Rider account not found. Check your phone number.');
        }

        // Create new token for this session without wiping active sessions on other devices
        $token = $rider->createToken('rider-session', ['role:rider'])->plainTextToken;

        // Fetch any existing ongoing ride (requested or accepted)
        $activeRide = $rider->rides()
            ->whereIn('status', [RideStatus::Requested->value, RideStatus::Accepted->value])
            ->with(['rider', 'driver', 'events'])
            ->latest()
            ->first();

        return response()->json([
            'data' => [
                'token'      => $token,
                'userType'   => 'rider',
                'id'         => $rider->id,
                'fullName'   => $rider->full_name,
                'phone'      => $rider->phone,
                'activeRide' => $activeRide ? RidePresenter::present($activeRide) : null,
            ],
        ]);
    }

    /**
     * Driver login — phone lookup, issue a Sanctum token with ability 'role:driver'.
     */
    public function driverLogin(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
        ], [
            'phone.regex' => 'The phone number format is invalid. Must be an international or standard phone number (e.g. +923211111101).',
        ]);

        $driver = Driver::query()
            ->where('phone', $request->string('phone')->toString())
            ->first();

        if ($driver === null) {
            throw ApiException::validation('Driver account not found. Check your phone number.');
        }

        $token = $driver->createToken('driver-session', ['role:driver'])->plainTextToken;

        return response()->json([
            'data' => [
                'token'           => $token,
                'userType'        => 'driver',
                'id'              => $driver->id,
                'fullName'        => $driver->full_name,
                'phone'           => $driver->phone,
                'vehicleCategory' => $driver->vehicle_category->value,
                'vehicleModel'    => $driver->vehicle_model,
                'vehiclePlate'    => $driver->vehicle_plate,
                'rating'          => $driver->rating,
                'status'          => $driver->status->value,
                'currentLat'      => $driver->current_lat,
                'currentLng'      => $driver->current_lng,
            ],
        ]);
    }

    public function riderActiveRide(): JsonResponse
    {
        $riderId = $this->actor->requireRiderId();
        $rider = Rider::query()->find($riderId) ?? throw ApiException::riderNotFound($riderId);

        $activeRide = $rider->rides()
            ->whereIn('status', [RideStatus::Requested->value, RideStatus::Accepted->value])
            ->with(['rider', 'driver', 'events'])
            ->latest()
            ->first();

        return response()->json([
            'data' => [
                'activeRide' => $activeRide ? RidePresenter::present($activeRide) : null,
            ],
        ]);
    }

    /**
     * Driver feed — requires a valid driver Sanctum token.
     * Returns the driver's active ride (if any) and a list of
     * incoming unassigned rides matching their vehicle category.
     */
    public function driverFeed(): JsonResponse
    {
        $driverId = $this->actor->requireDriverId();
        $driver   = Driver::query()->find($driverId) ?? throw ApiException::driverNotFound($driverId);

        // Current active ride assigned to this driver
        $activeRide = Ride::query()
            ->where('driver_id', $driverId)
            ->where('status', RideStatus::Accepted->value)
            ->with(['rider', 'driver', 'events'])
            ->latest()
            ->first();

        // Incoming unassigned rides matching driver category
        $incomingRides = [];
        if ($activeRide === null && $driver->status === DriverStatus::Available) {
            $incomingRides = Ride::query()
                ->where('status', RideStatus::Requested->value)
                ->where(function ($query) use ($driver): void {
                    // Comfort drivers can take Economy too
                    if ($driver->vehicle_category->value === 'comfort') {
                        $query->whereIn('category', ['economy', 'comfort']);
                    } else {
                        $query->where('category', 'economy');
                    }
                })
                ->with(['rider'])
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn (Ride $ride) => RidePresenter::present($ride));
        }

        return response()->json([
            'data' => [
                'driver'       => DriverPresenter::present($driver),
                'activeRide'   => $activeRide ? RidePresenter::present($activeRide) : null,
                'incomingRides' => $incomingRides,
            ],
        ]);
    }
}
