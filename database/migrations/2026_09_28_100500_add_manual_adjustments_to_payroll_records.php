<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phiếu lương từng người: Kế toán điều chỉnh tay các khoản (phụ cấp, thưởng khác,
     * khấu trừ khác, ghi chú) khi kỳ chưa duyệt. Các khoản này được GIỮ khi bấm
     * "Đồng bộ & Tính lại" (allowance_override NULL = dùng phụ cấp theo cấu hình).
     */
    public function up(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->decimal('allowance_override', 15, 2)->nullable()->after('allowance');
            $table->decimal('other_bonus', 15, 2)->default(0)->after('allowance_override');
            $table->decimal('other_deduction', 15, 2)->default(0)->after('foreign_teacher_deduction');
            $table->text('adjustment_notes')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->dropColumn(['allowance_override', 'other_bonus', 'other_deduction', 'adjustment_notes']);
        });
    }
};
