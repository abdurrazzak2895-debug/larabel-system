<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('available_balance', 18, 2)->default(0);
            $table->decimal('reserved_balance', 18, 2)->default(0);
            $table->decimal('credit_limit', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('user_wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_wallet_id')->constrained('user_wallets')->cascadeOnDelete();
            $table->string('type')->comment('deposit | booking_hold | booking_debit | refund | manual_adjustment');
            $table->decimal('amount', 18, 2);
            $table->string('reference')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_wallet_id', 'type']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('portal_booking_fee', 18, 2)->nullable()->after('status');
        });

        Schema::table('deposit_requests', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('agency_id')->constrained()->nullOnDelete();
            $table->index(['agency_id', 'user_id', 'status']);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->decimal('portal_booking_fee', 18, 2)->nullable()->after('booking_status');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('portal_booking_fee');
        });

        Schema::table('deposit_requests', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['agency_id', 'user_id', 'status']);
            $table->dropColumn('user_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('portal_booking_fee');
        });

        Schema::dropIfExists('user_wallet_transactions');
        Schema::dropIfExists('user_wallets');
    }
};
