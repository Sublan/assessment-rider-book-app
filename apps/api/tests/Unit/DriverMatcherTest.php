<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\RideCategory;
use App\Models\Driver;
use App\Services\DriverMatcher;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DriverMatcherTest extends TestCase
{
    private DriverMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matcher = new DriverMatcher([
            'approach_speed_kmh' => 18,
            'dispatch_overhead_minutes' => 3,
            'max_candidates' => 5,
        ]);
    }

    #[Test]
    public function it_ranks_the_nearest_driver_first(): void
    {
        $near = $this->driver('near', 31.5204, 74.3440);
        $far = $this->driver('far', 31.4697, 74.4123);

        $ranked = $this->matcher->rank(collect([$far, $near]), 31.5204, 74.3440, RideCategory::Economy, 'Lahore');

        $this->assertCount(2, $ranked);
        $this->assertSame($near, $ranked[0]['driver']);
        $this->assertSame(0, $ranked[0]['distance_meters']);
        $this->assertGreaterThan(0, $ranked[1]['distance_meters']);
    }

    #[Test]
    public function it_excludes_drivers_who_are_offline_on_a_trip_or_inactive(): void
    {
        $drivers = collect([
            $this->driver('online', 31.5204, 74.3440),
            $this->driver('offline', 31.5204, 74.3440, ['status' => 'offline']),
            $this->driver('busy', 31.5204, 74.3440, ['status' => 'on_trip']),
            $this->driver('deactivated', 31.5204, 74.3440, ['is_active' => false]),
        ]);

        $ranked = $this->matcher->rank($drivers, 31.5204, 74.3440, RideCategory::Economy, 'Lahore');

        $this->assertCount(1, $ranked);
        $this->assertSame('online', $ranked[0]['driver']->full_name);
    }

    #[Test]
    public function it_excludes_drivers_in_another_city(): void
    {
        $ranked = $this->matcher->rank(
            collect([$this->driver('karachi', 31.5204, 74.3440, ['city' => 'Karachi'])]),
            31.5204,
            74.3440,
            RideCategory::Economy,
            'Lahore',
        );

        $this->assertSame([], $ranked);
    }

    #[Test]
    public function an_economy_vehicle_cannot_be_matched_to_a_comfort_request(): void
    {
        $economy = collect([$this->driver('eco', 31.5204, 74.3440, ['vehicle_category' => 'economy'])]);

        $this->assertSame([], $this->matcher->rank($economy, 31.5204, 74.3440, RideCategory::Comfort, 'Lahore'));
        $this->assertCount(1, $this->matcher->rank($economy, 31.5204, 74.3440, RideCategory::Economy, 'Lahore'));
    }

    #[Test]
    public function a_comfort_vehicle_is_matched_to_both_categories(): void
    {
        $comfort = collect([$this->driver('comfy', 31.5204, 74.3440, ['vehicle_category' => 'comfort'])]);

        $this->assertCount(1, $this->matcher->rank($comfort, 31.5204, 74.3440, RideCategory::Comfort, 'Lahore'));
        $this->assertCount(1, $this->matcher->rank($comfort, 31.5204, 74.3440, RideCategory::Economy, 'Lahore'));
    }

    #[Test]
    public function equidistant_drivers_are_ordered_by_rating_then_id_so_the_choice_is_stable(): void
    {
        $lowRating = $this->driver('low', 31.5204, 74.3440, ['rating' => 4.1], 'aaaaaaaa-0000-4000-8000-000000000001');
        $highRating = $this->driver('high', 31.5204, 74.3440, ['rating' => 4.9], 'bbbbbbbb-0000-4000-8000-000000000002');

        $ranked = $this->matcher->rank(collect([$lowRating, $highRating]), 31.5204, 74.3440, RideCategory::Economy, 'Lahore');
        $this->assertSame('high', $ranked[0]['driver']->full_name);

        $again = $this->matcher->rank(collect([$highRating, $lowRating]), 31.5204, 74.3440, RideCategory::Economy, 'Lahore');
        $this->assertSame('high', $again[0]['driver']->full_name);
    }

    #[Test]
    public function it_never_returns_more_than_the_candidate_limit(): void
    {
        $drivers = collect(range(1, 9))->map(fn () => $this->driver('d', 31.5204, 74.3440));

        $this->assertLessThanOrEqual(5, count($this->matcher->rank($drivers, 31.5204, 74.3440, RideCategory::Economy, 'Lahore')));
    }

    #[Test]
    public function the_eta_combines_driving_time_with_dispatch_overhead(): void
    {
        // ~1.2 km at 18 km/h is ~4 minutes, plus 3 minutes of dispatch overhead.
        $ranked = $this->matcher->rank(
            collect([$this->driver('close', 31.5300, 74.3440)]),
            31.5204,
            74.3440,
            RideCategory::Economy,
            'Lahore',
        );

        $this->assertGreaterThanOrEqual(5, $ranked[0]['eta_minutes']);
        $this->assertLessThanOrEqual(8, $ranked[0]['eta_minutes']);
    }

    #[Test]
    public function a_driver_without_a_location_is_not_a_candidate(): void
    {
        $ranked = $this->matcher->rank(
            collect([$this->driver('nowhere', null, null)]),
            31.5204,
            74.3440,
            RideCategory::Economy,
            'Lahore',
        );

        $this->assertSame([], $ranked);
    }

    /** @param array<string, mixed> $overrides */
    private function driver(string $name, ?float $lat, ?float $lng, array $overrides = [], ?string $id = null): Driver
    {
        $driver = new Driver([
            'full_name' => $name,
            'phone' => '+923000000000',
            'city' => 'Lahore',
            'vehicle_category' => 'economy',
            'vehicle_model' => 'Suzuki Alto',
            'vehicle_plate' => 'LEB-0001',
            'rating' => 4.5,
            'status' => 'available',
            'is_active' => true,
            'current_lat' => $lat,
            'current_lng' => $lng,
            ...$overrides,
        ]);

        $driver->id = $id ?? sprintf('00000000-0000-4000-8000-%012d', random_int(1, 999999));

        return $driver;
    }
}
