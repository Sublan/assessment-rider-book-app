<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console routes
|--------------------------------------------------------------------------
*/

/*
 * Idempotency rows are only useful for as long as a client might retry, so we
 * prune them on the stored TTL. This keeps the table bounded without ever
 * letting a delayed retry create a duplicate ride (a pruned key is simply a new
 * request).
 */
Artisan::command('rides:prune-idempotency-keys', function (): void {
    $hours = (int) config('ride.idempotency_ttl_hours');
    $cutoff = now()->subHours($hours);

    $deleted = DB::table('idempotency_keys')->where('created_at', '<', $cutoff)->delete();

    $this->info("Pruned {$deleted} idempotency key(s) older than {$hours}h.");
})->purpose('Delete expired Idempotency-Key rows');

Schedule::command('rides:prune-idempotency-keys')->daily();
