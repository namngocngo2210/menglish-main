<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Báo cáo có cấu trúc phân biệt bằng `kind` (khóa trong StaffReportController::STRUCTURED), không chỉ bằng `type`:
 * báo cáo tháng Học thuật và báo cáo tháng theo lớp của giáo viên cùng type "monthly", trước đây một tài khoản có cả
 * hai vai trò nộp loại này sẽ ghi đè loại kia cùng tháng. Báo cáo chữ tự do (không có period_key) để kind = null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_reports', function (Blueprint $table) {
            $table->string('kind', 30)->nullable()->after('type');
            $table->index(['user_id', 'kind', 'period_key'], 'staff_reports_kind_period_idx');
        });

        DB::table('staff_reports')->whereNotNull('period_key')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $data = json_decode((string) $row->data, true) ?: [];
                $kind = match ($row->type) {
                    'weekly' => 'weekly_kpi',
                    'monthly' => array_key_exists('general', $data) || array_key_exists('classes', $data) ? 'teacher_monthly' : 'academic_monthly',
                    'quarterly' => 'academic_quarterly',
                    default => null,
                };
                if ($kind) {
                    DB::table('staff_reports')->where('id', $row->id)->update(['kind' => $kind]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('staff_reports', function (Blueprint $table) {
            $table->dropIndex('staff_reports_kind_period_idx');
            $table->dropColumn('kind');
        });
    }
};
