<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only lifecycle log, written inside the same transaction as the state
 * change it describes. The rider app renders it as the ride timeline and it is
 * the cheapest way to debug "what happened to this ride".
 */
final class RideEvent extends Model
{
    protected $table = 'ride_events';

    public $timestamps = false;

    protected $fillable = ['ride_id', 'event_type', 'actor_type', 'actor_id', 'metadata', 'created_at'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Ride, $this> */
    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }
}
