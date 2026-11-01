<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\RideStatus;
use App\Exceptions\ApiException;
use App\Support\RideStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RideStateMachineTest extends TestCase
{
    /** @return list<array{0: RideStatus, 1: RideStatus}> */
    public static function allowedTransitions(): array
    {
        return [
            [RideStatus::Requested, RideStatus::Accepted],
            [RideStatus::Requested, RideStatus::Cancelled],
            [RideStatus::Accepted, RideStatus::Completed],
            [RideStatus::Accepted, RideStatus::Cancelled],
        ];
    }

    /** @return list<array{0: RideStatus, 1: RideStatus}> */
    public static function forbiddenTransitions(): array
    {
        return [
            [RideStatus::Requested, RideStatus::Completed],
            [RideStatus::Accepted, RideStatus::Requested],
            [RideStatus::Completed, RideStatus::Accepted],
            [RideStatus::Completed, RideStatus::Cancelled],
            [RideStatus::Cancelled, RideStatus::Accepted],
            [RideStatus::Cancelled, RideStatus::Completed],
        ];
    }

    #[Test]
    #[DataProvider('allowedTransitions')]
    public function it_allows_the_documented_transitions(RideStatus $from, RideStatus $to): void
    {
        RideStateMachine::assertCanTransition($from, $to);

        $this->assertTrue($from->canTransitionTo($to));
    }

    #[Test]
    #[DataProvider('forbiddenTransitions')]
    public function it_rejects_every_other_transition(RideStatus $from, RideStatus $to): void
    {
        $this->expectException(ApiException::class);

        RideStateMachine::assertCanTransition($from, $to);
    }

    #[Test]
    public function terminal_states_are_terminal(): void
    {
        $this->assertTrue(RideStatus::Cancelled->isTerminal());
        $this->assertTrue(RideStatus::Completed->isTerminal());
        $this->assertFalse(RideStatus::Requested->isTerminal());
        $this->assertFalse(RideStatus::Accepted->isTerminal());
    }

    #[Test]
    public function only_requested_and_accepted_rides_can_be_cancelled(): void
    {
        $this->assertTrue(RideStatus::Requested->isCancellable());
        $this->assertTrue(RideStatus::Accepted->isCancellable());
        $this->assertFalse(RideStatus::Completed->isCancellable());
        $this->assertFalse(RideStatus::Cancelled->isCancellable());
    }

    #[Test]
    public function an_invalid_transition_reports_the_from_state(): void
    {
        try {
            RideStateMachine::assertCanTransition(RideStatus::Completed, RideStatus::Cancelled);
            $this->fail('expected an ApiException');
        } catch (ApiException $exception) {
            $this->assertSame('INVALID_STATE_TRANSITION', $exception->errorCode->value);
            $this->assertSame('completed', $exception->details['from']);
            $this->assertSame(409, $exception->status());
        }
    }
}
