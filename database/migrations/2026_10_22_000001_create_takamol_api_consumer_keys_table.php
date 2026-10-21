<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('takamol_api_consumer_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('key_prefix', 20);
            $table->char('key_hash', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedSmallInteger('rate_limit_per_minute')->default(60);
            $table->timestamps();

            $table->index(['revoked_at', 'expires_at'], 'takamol_api_keys_usable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('takamol_api_consumer_keys');
    }
};
