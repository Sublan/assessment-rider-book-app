<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\RideFixtures;

abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    use RefreshDatabase;
    use RideFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        // The API is throttled; test cases must not inherit each other's budget.
        Cache::flush();
    }
}
