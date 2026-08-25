<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposit_requests', function (Blueprint $table): void {
            $table->string('mfs_sender_phone', 32)->nullable()->after('payment_method');
            $table->string('mfs_transaction_id', 128)->nullable()->after('mfs_sender_phone');
            $table->unique(['payment_method', 'mfs_transaction_id'], 'deposit_mfs_method_transaction_unique');
        });
    }

    public function down(): void
    {
        Schema::table('deposit_requests', function (Blueprint $table): void {
            $table->dropUnique('deposit_mfs_method_transaction_unique');
            $table->dropColumn(['mfs_sender_phone', 'mfs_transaction_id']);
        });
    }
};
