<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_availability_credentials', function (Blueprint $table): void {
            $table->unsignedInteger('recovery_failures')->default(0)->after('last_error');
            $table->timestamp('circuit_open_until')->nullable()->after('recovery_failures');
            $table->timestamp('last_recovered_at')->nullable()->after('circuit_open_until');
            $table->index(['active', 'circuit_open_until'], 'portal_credentials_recovery_idx');
        });
    }

    public function down(): void
    {
        Schema::table('portal_availability_credentials', function (Blueprint $table): void {
            $table->dropIndex('portal_credentials_recovery_idx');
            $table->dropColumn([
                'recovery_failures',
                'circuit_open_until',
                'last_recovered_at',
            ]);
        });
    }
};
