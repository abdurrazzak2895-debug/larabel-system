<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->string('svp_user_id')->nullable();
            $table->string('full_name');
            $table->string('national_id')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->json('svp_data')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'user_id']);
            $table->unique(['user_id', 'svp_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
