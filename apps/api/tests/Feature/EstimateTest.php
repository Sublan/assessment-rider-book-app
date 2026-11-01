<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EstimateTest extends TestCase
{
    #[Test]
    public function it_returns_a_fare_and_a_pickup_eta_before_the_ride_exists(): void
    {
        $this->makeDriver(['current_lat' => 31.5204, 'current_lng' => 74.3440]);

        $this->postJson('/api/rides/estimate', [
            'category' => 'economy',
            'pickup' => ['lat' => 31.5204, 'lng' => 74.3440],
            'destination' => ['lat' => 31.4697, 'lng' => 74.4123],
        ])
            ->assertOk()
            ->assertJsonPath('data.category', 'economy')
            ->assertJsonPath('data.currency', 'PKR')
            ->assertJsonPath('data.availableDrivers', 1)
            ->assertJsonStructure(['data' => ['fare' => ['totalMinor', 'totalPkr', 'breakdown'], 'distanceMeters', 'durationSeconds', 'pickupEtaMinutes']]);
    }

    #[Test]
    public function comfort_costs_more_than_economy_for_the_same_trip(): void
    {
        $payload = [
            'pickup' => ['lat' => 31.5204, 'lng' => 74.3440],
            'destination' => ['lat' => 31.4697, 'lng' => 74.4123],
        ];

        $economy = $this->postJson('/api/rides/estimate', ['category' => 'economy', ...$payload])->json('data.fare.totalMinor');
        $comfort = $this->postJson('/api/rides/estimate', ['category' => 'comfort', ...$payload])->json('data.fare.totalMinor');

        $this->assertGreaterThan($economy, $comfort);
    }

    #[Test]
    public function it_reports_no_drivers_and_no_eta_when_the_city_is_empty(): void
    {
        $this->postJson('/api/rides/estimate', [
            'category' => 'economy',
            'pickup' => ['lat' => 31.5204, 'lng' => 74.3440],
            'destination' => ['lat' => 31.4697, 'lng' => 74.4123],
        ])
            ->assertOk()
            ->assertJsonPath('data.availableDrivers', 0)
            ->assertJsonPath('data.pickupEtaMinutes', null);
    }

    #[Test]
    public function it_rejects_an_invalid_category(): void
    {
        $this->postJson('/api/rides/estimate', [
            'category' => 'rocket',
            'pickup' => ['lat' => 31.5204, 'lng' => 74.3440],
            'destination' => ['lat' => 31.4697, 'lng' => 74.4123],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    #[Test]
    public function it_rejects_a_trip_outside_lahore(): void
    {
        $this->postJson('/api/rides/estimate', [
            'category' => 'economy',
            'pickup' => ['lat' => 31.5204, 'lng' => 74.3440],
            'destination' => ['lat' => 24.8607, 'lng' => 67.0011],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OUTSIDE_SERVICE_AREA');
    }

    #[Test]
    public function it_lists_lahore_locations_for_the_picker(): void
    {
        $this->getJson('/api/locations')
            ->assertOk()
            ->assertJsonPath('data.city', 'Lahore')
            ->assertJsonCount(10, 'data.locations');
    }
}
