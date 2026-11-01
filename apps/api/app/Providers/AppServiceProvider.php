<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\DriverMatcher;
use App\Services\FareCalculator;
use App\Services\TripPlanner;
use App\Support\ActorContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One actor per request: populated by ResolveActor, read by controllers.
        $this->app->scoped(ActorContext::class);

        $this->app->singleton(FareCalculator::class, fn () => new FareCalculator(config('ride.pricing')));

        $this->app->singleton(DriverMatcher::class, fn () => new DriverMatcher(config('ride.matching')));

        $this->app->singleton(TripPlanner::class, fn ($app) => new TripPlanner(
            $app->make(FareCalculator::class),
            $app->make(DriverMatcher::class),
            config('ride.matching'),
        ));
    }

    public function boot(): void
    {
        Schema::defaultStringLength(191);

        /*
         * Ride creation is throttled per rider (falling back to IP for
         * anonymous callers) so a buggy retry loop cannot flood the matcher.
         * This is a coarse safety net; Idempotency-Key is what makes retries
         * *safe*, throttling only bounds the damage of ones that are not.
         */
        RateLimiter::for('rides', static fn (Request $request) => Limit::perMinute(30)
            ->by($request->header('x-rider-id') ?: $request->ip()));
    }
}
