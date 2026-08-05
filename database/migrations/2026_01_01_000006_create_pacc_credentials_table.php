<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pacc_credentials', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->text('api_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['agency_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pacc_credentials');
    }
};
