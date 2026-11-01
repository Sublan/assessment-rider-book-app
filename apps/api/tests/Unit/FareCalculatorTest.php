<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\RideCategory;
use App\Services\FareCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FareCalculatorTest extends TestCase
{
    private FareCalculator $fares;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fares = new FareCalculator([
            'economy' => [
                'base_minor' => 16000,
                'per_km_minor' => 6500,
                'per_min_minor' => 1200,
                'minimum_minor' => 26000,
                'surge' => 1.0,
                'vehicle_example' => 'Suzuki Alto',
            ],
            'comfort' => [
                'base_minor' => 26000,
                'per_km_minor' => 9800,
                'per_min_minor' => 1800,
                'minimum_minor' => 42000,
                'surge' => 1.15,
                'vehicle_example' => 'Toyota Yaris',
            ],
        ]);
    }

    #[Test]
    public function it_is_deterministic_for_the_same_trip(): void
    {
        $first = $this->fares->quote(RideCategory::Economy, 8123, 1329);
        $second = $this->fares->quote(RideCategory::Economy, 8123, 1329);

        $this->assertSame($first, $second);
    }

    #[Test]
    public function it_applies_the_configured_minimum_fare(): void
    {
        $quote = $this->fares->quote(RideCategory::Economy, 0, 0);

        $this->assertSame(26000, $quote['total_minor']);
    }

    #[Test]
    public function it_charges_distance_and_time_on_top_of_the_base(): void
    {
        $quote = $this->fares->quote(RideCategory::Economy, 10_000, 1_800);

        // 16.00 base + 10km * 6.50 + 30min * 1.20 = 16 + 65 + 36 = 117 PKR
        $this->assertSame(16000, $quote['base_minor']);
        $this->assertSame(65000, $quote['distance_minor']);
        $this->assertSame(36000, $quote['time_minor']);
        $this->assertSame(117000, $quote['total_minor']);
    }

    #[Test]
    public function it_applies_the_surge_multiplier_once(): void
    {
        $economy = $this->fares->quote(RideCategory::Economy, 10_000, 1_800);
        $comfort = $this->fares->quote(RideCategory::Comfort, 10_000, 1_800);

        // comfort: (260 + 980 + 540) * 1.15 = 2047 PKR -> 204700 paisa
        $this->assertSame(204700, $comfort['total_minor']);
        $this->assertGreaterThan($economy['total_minor'], $comfort['total_minor']);
        $this->assertSame(1.15, $comfort['surge_multiplier']);
    }

    #[Test]
    public function the_breakdown_never_exceeds_the_total(): void
    {
        $quote = $this->fares->quote(RideCategory::Comfort, 4_400, 720);

        $components = $quote['base_minor'] + $quote['distance_minor'] + $quote['time_minor'];

        $this->assertLessThanOrEqual($quote['total_minor'], $components);
    }

    #[Test]
    public function an_unknown_category_is_a_programming_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // @phpstan-ignore-next-line intentionally invalid enum value
        (new FareCalculator([]))->quote(RideCategory::Economy, 1000, 60);
    }
}
