<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

final class Rider extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasUuids;

    protected $table = 'riders';

    protected $fillable = ['full_name', 'phone'];

    /** @return HasMany<Ride, $this> */
    public function rides(): HasMany
    {
        return $this->hasMany(Ride::class);
    }

    /** @return HasMany<Ride, $this> */
    public function activeRides(): HasMany
    {
        return $this->rides()->whereIn('status', ['requested', 'accepted']);
    }
}
