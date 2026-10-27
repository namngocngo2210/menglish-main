<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gộp màn chấm bài nộp (chủ dự án 27/10/2026): bài nộp theo giỏ chấm được điểm + nhận xét như bài nộp cổng học viên.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('homework_submissions', function (Blueprint $table) {
            $table->string('score', 20)->nullable()->after('status');
            $table->text('feedback')->nullable()->after('score');
        });
    }

    public function down(): void
    {
        Schema::table('homework_submissions', function (Blueprint $table) {
            $table->dropColumn(['score', 'feedback']);
        });
    }
};
