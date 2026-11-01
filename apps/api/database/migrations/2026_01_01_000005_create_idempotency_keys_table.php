<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function ($table) {
            $table->string('key', 190);
            $table->string('scope', 190);
            $table->string('request_hash', 64);
            $table->integer('response_code')->nullable();
            $table->json('response_body')->nullable();
            $table->uuid('resource_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['key', 'scope']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
