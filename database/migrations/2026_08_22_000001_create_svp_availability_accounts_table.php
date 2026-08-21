<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('svp_availability_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'token_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('svp_availability_accounts');
    }
};
