<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('svp_user_id');
            $table->index(['user_id', 'is_active']);
            $table->index(['agency_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table): void {
            $table->dropIndex('candidates_user_id_is_active_index');
            $table->dropIndex('candidates_agency_id_is_active_index');
            $table->dropColumn('is_active');
        });
    }
};
