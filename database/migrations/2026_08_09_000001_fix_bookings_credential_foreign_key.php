<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix the bookings.credential_id foreign key.
 *
 * The original 2026_01_01_000007 migration constrained credential_id to
 * pacc_credentials — but the application always writes candidates.id into
 * that column (BookingController::store passes $candidate->id, and
 * Booking::credential() is a belongsTo Candidate relation). Because nothing
 * ever inserts rows into pacc_credentials, every real booking attempt hit a
 * FOREIGN KEY constraint violation and 500'd.
 *
 * This migration must run AFTER candidates is created (2026_01_01_000013),
 * so it is timestamped later than that migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Drop the old constraint that pointed at pacc_credentials.
            $table->dropForeign(['credential_id']);

            // Re-point it at the candidates table, which is where the
            // credential_id value actually comes from.
            $table->foreign('credential_id')
                ->references('id')
                ->on('candidates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['credential_id']);

            $table->foreign('credential_id')
                ->references('id')
                ->on('pacc_credentials')
                ->nullOnDelete();
        });
    }
};
