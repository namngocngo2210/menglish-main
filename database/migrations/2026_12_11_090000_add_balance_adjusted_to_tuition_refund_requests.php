<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-07: chuyển nhượng học phí không lập phiếu thu / không cấp số hóa đơn. Hồ sơ duyệt theo cách mới đánh dấu
 * balance_adjusted = true và StudentTuition::recalculateDebt() cộng/trừ refund_amount vào "đã thu" của 2 bên.
 * Hồ sơ cũ (đã có phiếu XFER-OUT/XFER-IN) giữ false → không bị tính 2 lần, dữ liệu lịch sử không bị đổi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tuition_refund_requests', function (Blueprint $table) {
            $table->boolean('balance_adjusted')->default(false);
            $table->unsignedInteger('transferred_sessions')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('tuition_refund_requests', function (Blueprint $table) {
            $table->dropColumn(['balance_adjusted', 'transferred_sessions']);
        });
    }
};
