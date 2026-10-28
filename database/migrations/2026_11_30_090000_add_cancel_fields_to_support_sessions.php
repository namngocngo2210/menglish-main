<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hủy / xếp lại buổi bổ trợ (chủ dự án 28/10/2026): Học vụ hủy buổi, hoặc học sinh vắng buổi bổ trợ (điểm danh) → buổi
 * chuyển "Đã hủy", dòng gốc quay lại danh sách chờ xếp buổi. Lưu lý do + thời điểm hủy để Học vụ biết vì sao xếp lại.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_sessions', function (Blueprint $table) {
            $table->text('cancel_reason')->nullable()->after('completion_note');
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('support_sessions', function (Blueprint $table) {
            $table->dropColumn(['cancel_reason', 'cancelled_at']);
        });
    }
};
