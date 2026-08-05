<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('payment_method');
            $table->string('receipt_path')->nullable();
            $table->string('status')->default('pending')
                ->comment('pending | approved | rejected');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'status']);
            $table->index('created_at');
        });

        Schema::create('refund_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->text('reason');
            $table->string('status')->default('pending')
                ->comment('pending | approved | rejected | processed');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
        Schema::dropIfExists('deposit_requests');
    }
};
