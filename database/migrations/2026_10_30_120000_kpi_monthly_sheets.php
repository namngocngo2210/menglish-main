<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tiêu chí Học vụ có số liệu trong hệ thống → nguồn đếm tự động (KpiCriterion::AUTO_SOURCES). */
    private const ACADEMIC_AUTO = [
        'Quy trình nhắc & follow học phí đúng hạn' => 'tuition_no_reminder',
        'Thu đúng học phí' => 'receipt_rejected',
        'Case học sinh bỏ sót' => 'care_overdue',
        'Xử lý data/test tuyển sinh' => 'crm_sla_late',
        'Nộp báo cáo đúng hạn' => 'daily_report_late',
        'Cơ sở vật chất & xuất nhập sách' => 'material_stock',
    ];

    /**
     * Phiếu KPI tháng: tiêu chí có nguồn số liệu tự động (trống = người chấm điền tay); phiếu có thêm trạng thái
     * "Không duyệt" kèm lý do; mỗi dòng phiếu lưu lại các bản ghi hệ thống đã đếm lúc duyệt.
     */
    public function up(): void
    {
        Schema::table('kpi_criteria', function (Blueprint $table) {
            $table->string('auto_source', 40)->nullable()->after('unit');
        });
        Schema::table('kpi_evaluations', function (Blueprint $table) {
            $table->text('reject_reason')->nullable()->after('status');
            $table->timestamp('decided_at')->nullable()->after('reject_reason');
        });
        Schema::table('kpi_evaluation_items', function (Blueprint $table) {
            $table->json('evidence')->nullable()->after('note');
        });

        foreach (self::ACADEMIC_AUTO as $name => $source) {
            DB::table('kpi_criteria')->where('role', 'academic_staff')->where('name', $name)->update(['auto_source' => $source]);
        }
    }

    public function down(): void
    {
        Schema::table('kpi_evaluation_items', fn (Blueprint $table) => $table->dropColumn('evidence'));
        Schema::table('kpi_evaluations', fn (Blueprint $table) => $table->dropColumn(['reject_reason', 'decided_at']));
        Schema::table('kpi_criteria', fn (Blueprint $table) => $table->dropColumn('auto_source'));
    }
};
