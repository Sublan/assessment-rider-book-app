<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The audit trail of "which driver held which ride". `rides.driver_id` is the
 * fast current-state pointer; this table is the history, so a cancelled or
 * completed ride still shows who was assigned and for how long.
 */
final class DriverAssignment extends Model
{
    use HasUuids;

    protected $table = 'driver_assignments';

    public $timestamps = false;

    protected $fillable = ['ride_id', 'driver_id', 'assigned_at', 'ended_at', 'end_reason'];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Ride, $this> */
    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    /** @return BelongsTo<Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
