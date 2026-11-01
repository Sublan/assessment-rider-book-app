<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A claimed Idempotency-Key plus the response it produced. There is no
 * auto-increment id: the (key, scope) pair is the primary key, which is what
 * makes the claim atomic.
 */
final class IdempotencyKey extends Model
{
    protected $table = 'idempotency_keys';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = [
        'key',
        'scope',
        'request_hash',
        'response_code',
        'response_body',
        'resource_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
