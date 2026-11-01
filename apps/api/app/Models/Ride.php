<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RideCategory;
use App\Enums\RideStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Ride extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'rides';

    protected $fillable = [
        'rider_id',
        'status',
        'category',
        'city',
        'pickup_label',
        'pickup_lat',
        'pickup_lng',
        'destination_label',
        'destination_lat',
        'destination_lng',
        'distance_meters',
        'duration_seconds',
        'pickup_eta_minutes',
        'currency',
        'fare_base_minor',
        'fare_distance_minor',
        'fare_time_minor',
        'fare_minimum_minor',
        'surge_multiplier',
        'fare_total_minor',
        'driver_id',
        'requested_at',
        'accepted_at',
        'completed_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => RideStatus::class,
            'category' => RideCategory::class,
            'pickup_lat' => 'float',
            'pickup_lng' => 'float',
            'destination_lat' => 'float',
            'destination_lng' => 'float',
            'distance_meters' => 'int',
            'duration_seconds' => 'int',
            'pickup_eta_minutes' => 'int',
            'fare_base_minor' => 'int',
            'fare_distance_minor' => 'int',
            'fare_time_minor' => 'int',
            'fare_minimum_minor' => 'int',
            'surge_multiplier' => 'float',
            'fare_total_minor' => 'int',
            'requested_at' => 'datetime',
            'accepted_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Rider, $this> */
    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }

    /** @return BelongsTo<Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /** @return HasOne<DriverAssignment, $this> */
    public function assignment(): HasOne
    {
        return $this->hasOne(DriverAssignment::class);
    }

    /** @return HasMany<RideEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(RideEvent::class)->orderBy('id');
    }

    public function isCancellable(): bool
    {
        return $this->status->isCancellable();
    }
}
