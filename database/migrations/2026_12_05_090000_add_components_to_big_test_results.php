<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TK-2026-0056: điểm Big Test theo thành phần GV tự đặt [{name, score}]. Bốn cột kỹ năng + overall_score cũ giữ nguyên
 * để bản ghi cũ vẫn đọc được (BigTestResult::components() đọc cả hai dạng) — không chuyển đổi dữ liệu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('big_test_results', function (Blueprint $table) {
            $table->json('components')->nullable()->after('speaking_score');
        });
    }

    public function down(): void
    {
        Schema::table('big_test_results', function (Blueprint $table) {
            $table->dropColumn('components');
        });
    }
};
