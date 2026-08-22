<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_availability_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('portal_account_id', 120);
            $table->text('session_cookie');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'expires_at']);
            $table->index('portal_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_availability_credentials');
    }
};
