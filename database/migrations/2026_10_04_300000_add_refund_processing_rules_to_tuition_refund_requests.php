<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A6 (25/09/2026) "Hoàn phí": ưu tiên chuyển nhượng buổi dư — hồ sơ hoàn tiền phải ghi lý do không chuyển nhượng;
     * hoàn tiền bắt buộc ảnh bằng chứng (lưu riêng tư khi Admin duyệt chi); lý do từ chối để người lập biết.
     * Hạn xử lý (min(ngày lập + 7 ngày, cuối tháng phát sinh)) tính từ created_at, không lưu cột.
     */
    public function up(): void
    {
        Schema::table('tuition_refund_requests', function (Blueprint $table) {
            $table->text('no_transfer_reason')->nullable()->after('reason');
            $table->string('proof_path')->nullable()->after('no_transfer_reason');
            $table->string('rejection_reason', 1000)->nullable()->after('proof_path');
        });
    }

    public function down(): void
    {
        Schema::table('tuition_refund_requests', function (Blueprint $table) {
            $table->dropColumn(['no_transfer_reason', 'proof_path', 'rejection_reason']);
        });
    }
};
