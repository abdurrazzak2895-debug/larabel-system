<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_svp_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidate_id')->unique()->constrained('candidates')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->string('svp_user_id')->nullable();
            $table->longText('access_token');
            $table->longText('csrf_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'agency_id']);
            $table->index(['user_id', 'svp_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_svp_sessions');
    }
};
