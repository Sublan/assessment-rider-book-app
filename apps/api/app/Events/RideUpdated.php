<?php

declare(strict_types=1);

namespace App\Events;

use App\Http\Presenters\RidePresenter;
use App\Models\Ride;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class RideUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $ride;
    public string $rideId;

    public function __construct(Ride $ride)
    {
        $this->rideId = $ride->id;
        $this->ride = RidePresenter::present($ride);
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('rides'),
            new Channel("ride.{$this->rideId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'RideUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'ride' => $this->ride,
        ];
    }
}
