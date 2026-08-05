<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_wallets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->decimal('available_balance', 18, 2)->default(0);
            $table->decimal('reserved_balance', 18, 2)->default(0);
            $table->decimal('credit_limit', 18, 2)->default(0);
            $table->timestamps();

            $table->unique('agency_id');
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('wallet_id')->constrained('agency_wallets')->cascadeOnDelete();
            $table->enum('type', [
                'deposit',
                'booking_hold',
                'booking_debit',
                'refund',
                'manual_adjustment',
            ]);
            $table->decimal('amount', 18, 2);
            $table->string('reference')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'type']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('agency_wallets');
    }
};
