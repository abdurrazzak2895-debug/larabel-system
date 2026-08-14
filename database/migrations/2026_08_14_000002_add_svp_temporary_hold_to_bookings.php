<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('temporary_hold_id')->nullable()->after('exam_date');
            $table->string('temporary_hold_expires_at')->nullable()->after('temporary_hold_id');
            $table->index('temporary_hold_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['temporary_hold_id']);
            $table->dropColumn(['temporary_hold_id', 'temporary_hold_expires_at']);
        });
    }
};
