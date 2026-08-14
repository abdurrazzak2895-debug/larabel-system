<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('category_id')->nullable()->after('occupation_id');
            $table->string('test_center_id')->nullable()->after('category_id');
            $table->string('test_center_name')->nullable()->after('test_center_id');
            $table->string('exam_session_name')->nullable()->after('exam_session_id');
            $table->date('exam_date')->nullable()->after('exam_session_name');

            $table->index(['category_id', 'test_center_id']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['bookings_category_id_test_center_id_index']);
            $table->dropColumn([
                'category_id',
                'test_center_id',
                'test_center_name',
                'exam_session_name',
                'exam_date',
            ]);
        });
    }
};
