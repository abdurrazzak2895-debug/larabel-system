<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('credential_id')->nullable()->constrained('pacc_credentials')->nullOnDelete();
            $table->string('occupation_id')->nullable();
            $table->string('exam_session_id')->nullable();
            $table->string('booking_status')->default('pending')
                ->comment('pending | processing | booked | failed | cancelled | refunded');
            $table->string('booking_reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('booking_status');
            $table->index('created_at');
            $table->index(['agency_id', 'booking_status']);
            $table->index(['user_id', 'booking_status']);
        });

        Schema::create('booking_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->json('payload')->nullable();
            $table->json('provider_response')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'event_type']);
            $table->index('created_at');
        });

        Schema::create('booking_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->json('request_payload')->nullable();
            $table->json('provider_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_attempts');
        Schema::dropIfExists('booking_logs');
        Schema::dropIfExists('bookings');
    }
};
