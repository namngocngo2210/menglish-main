<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chế độ làm bài khoá màn hình (link test đầu vào): lưu số lần thí sinh rời bài thi
 * (chuyển tab/ứng dụng, thoát toàn màn hình), nhật ký từng lần và bài có bị tự nộp do vi phạm hay không,
 * để Học vụ xem khi chấm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('placement_test_submissions', function (Blueprint $table) {
            $table->unsignedSmallInteger('violation_count')->default(0)->after('answers');
            $table->json('violation_log')->nullable()->after('violation_count');
            $table->boolean('auto_submitted')->default(false)->after('violation_log');
        });
    }

    public function down(): void
    {
        Schema::table('placement_test_submissions', function (Blueprint $table) {
            $table->dropColumn(['violation_count', 'violation_log', 'auto_submitted']);
        });
    }
};
