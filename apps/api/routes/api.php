<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DirectoryController;
use App\Http\Controllers\RideController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Ride-booking API (v1)
|--------------------------------------------------------------------------
|
| Everything is stateless JSON. Callers identify themselves with the
| `X-Rider-Id` / `X-Driver-Id` headers (see ResolveActor); every mutation that
| changes a ride is additionally protected by an `Idempotency-Key`.
|
| Throttling is applied per route rather than globally so the demo diagnostics
| endpoints stay usable while the write paths stay protected.
|
*/

Route::get('/health', [DirectoryController::class, 'health']);

Route::post('/auth/rider/login', [AuthController::class, 'riderLogin']);
Route::post('/auth/driver/login', [AuthController::class, 'driverLogin']);
Route::get('/rider/active-ride', [AuthController::class, 'riderActiveRide'])->middleware('throttle:120,1');
Route::get('/driver/feed', [AuthController::class, 'driverFeed'])->middleware('throttle:120,1');

Route::middleware('throttle:120,1')->group(function (): void {
    Route::get('/locations', [DirectoryController::class, 'locations']);
    Route::get('/riders', [DirectoryController::class, 'riders']);
    Route::get('/drivers', [DirectoryController::class, 'drivers']);
});

Route::post('/rides/estimate', [RideController::class, 'estimate'])->middleware('throttle:120,1');

Route::post('/rides', [RideController::class, 'store'])->middleware('throttle:30,1');
Route::get('/rides/{ride}', [RideController::class, 'show'])->middleware('throttle:240,1');

Route::post('/rides/{ride}/accept', [RideController::class, 'accept'])->middleware('throttle:60,1');
Route::post('/rides/{ride}/cancel', [RideController::class, 'cancel'])->middleware('throttle:60,1');
Route::post('/rides/{ride}/complete', [RideController::class, 'complete'])->middleware('throttle:60,1');

Route::patch('/drivers/{driver}/status', [DirectoryController::class, 'updateDriverStatus'])->middleware('throttle:60,1');
