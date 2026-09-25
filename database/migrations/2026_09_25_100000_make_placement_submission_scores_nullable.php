<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Điểm kỹ năng chưa chấm phải là NULL (không phải 0 / B1 mặc định):
 * bài nộp online chờ Học vụ chấm Writing/Speaking, nhập điểm CRM có thể thiếu kỹ năng.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('placement_test_submissions', function (Blueprint $table) {
            $table->decimal('listening_score', 4, 1)->nullable()->default(null)->change();
            $table->decimal('reading_score', 4, 1)->nullable()->default(null)->change();
            $table->decimal('writing_score', 4, 1)->nullable()->default(null)->change();
            $table->decimal('speaking_score', 4, 1)->nullable()->default(null)->change();
            $table->decimal('overall_score', 4, 1)->nullable()->default(null)->change();
            $table->string('cefr_level', 255)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('placement_test_submissions', function (Blueprint $table) {
            $table->decimal('listening_score', 4, 1)->default(0)->nullable(false)->change();
            $table->decimal('reading_score', 4, 1)->default(0)->nullable(false)->change();
            $table->decimal('writing_score', 4, 1)->default(0)->nullable(false)->change();
            $table->decimal('speaking_score', 4, 1)->default(0)->nullable(false)->change();
            $table->decimal('overall_score', 4, 1)->default(0)->nullable(false)->change();
            $table->string('cefr_level', 255)->default('B1')->nullable(false)->change();
        });
    }
};
