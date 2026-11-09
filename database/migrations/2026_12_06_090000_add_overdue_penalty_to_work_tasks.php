<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TK-2026-0078: mỗi việc lưu nguồn phạt quá hạn.
     * - Việc giao tay: phat_khi_qua_han (bật/tắt) + muc_phat_qua_han (số tiền cố định, > 0 khi bật).
     * - Việc SLA tự sinh: sla_rule_key trỏ tới mốc SLA đã tạo việc (bật/tắt, mức, bậc phạt đọc từ Cấu hình SLA).
     * Việc cũ mặc định không phạt (không phạt hồi tố).
     */
    public function up(): void
    {
        Schema::table('work_tasks', function (Blueprint $table) {
            $table->boolean('phat_khi_qua_han')->default(false)->after('sla_breached_at');
            $table->decimal('muc_phat_qua_han', 12, 2)->nullable()->after('phat_khi_qua_han');
            $table->string('sla_rule_key', 100)->nullable()->after('muc_phat_qua_han');
        });
    }

    public function down(): void
    {
        Schema::table('work_tasks', function (Blueprint $table) {
            $table->dropColumn(['phat_khi_qua_han', 'muc_phat_qua_han', 'sla_rule_key']);
        });
    }
};
