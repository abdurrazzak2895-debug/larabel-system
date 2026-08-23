<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_availability_api_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portal_availability_credential_id')
                ->constrained('portal_availability_credentials')
                ->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('key_prefix', 32);
            $table->string('key_hash', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->default(60);
            $table->timestamps();

            $table->index(['portal_availability_credential_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_availability_api_keys');
    }
};
