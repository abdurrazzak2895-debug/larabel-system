<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table): void {
            $table->longText('svp_login_email')->nullable()->after('email');
            $table->longText('svp_login_password')->nullable()->after('svp_login_email');
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table): void {
            $table->dropColumn(['svp_login_email', 'svp_login_password']);
        });
    }
};
