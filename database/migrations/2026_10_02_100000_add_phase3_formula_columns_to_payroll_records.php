<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Công thức lương theo BA (A6 25/09/2026 — Q3): mỗi phiếu lương ghi rõ loại nhân sự
     * (Part-time / Full-time), từng khoản của công thức và các khoản nhập tay (giữ khi tính lại):
     * bậc KPI giữ HS, KPI tự do, lương buổi có GVNN, thuế TNCN, các dòng phụ cấp / khấu trừ tự do.
     */
    public function up(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->string('employee_type', 20)->nullable()->after('department');   // parttime | fulltime
            $table->string('salary_role', 30)->nullable()->after('employee_type');   // teacher_parttime | teacher_fulltime | academic_staff | academic_lead | sales | staff
            $table->unsignedInteger('teaching_sessions')->default(0)->after('actual_hours');
            $table->unsignedInteger('retention_base_students')->default(0)->after('teaching_sessions');
            $table->unsignedInteger('retention_students')->default(0)->after('retention_base_students');
            $table->decimal('retention_tier', 15, 2)->nullable()->after('retention_students');          // nhập tay: 15k/20k/25k
            $table->string('kpi_source', 20)->nullable()->after('kpi_bonus');                           // retention | manual | academic_kpi
            $table->decimal('kpi_manual_amount', 15, 2)->nullable()->after('kpi_source');               // nhập tay (GV FT, Học thuật, khác)
            $table->decimal('kpi_score', 6, 2)->nullable()->after('kpi_manual_amount');                 // Học vụ: tổng điểm KPI %
            $table->decimal('foreign_session_pay', 15, 2)->default(0)->after('kpi_score');              // nhập tay, chờ BA chốt
            $table->decimal('commission_deferred', 15, 2)->default(0)->after('commission_clawback');
            $table->decimal('commission_percent', 5, 2)->nullable()->after('commission_deferred');
            $table->unsignedInteger('commission_closed_count')->nullable()->after('commission_percent');
            $table->decimal('union_deduction', 15, 2)->default(0)->after('insurance_deduction');
            $table->json('manual_lines')->nullable()->after('adjustment_notes');
            $table->json('calculation_details')->nullable()->after('manual_lines');
        });

        // Điều chỉnh tay kiểu cũ (phụ cấp ghi đè / thưởng khác / khấu trừ khác) của kỳ CHƯA khóa → dòng tự do,
        // để lần tính lại theo công thức mới không làm mất. Kỳ đã duyệt/đã chi giữ nguyên.
        $openRecords = DB::table('payroll_records')
            ->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_records.payroll_period_id')
            ->whereNotIn('payroll_periods.status', ['approved', 'paid'])
            ->select('payroll_records.id', 'allowance_override', 'other_bonus', 'other_deduction')
            ->get();
        foreach ($openRecords as $record) {
            $lines = [];
            if ($record->allowance_override !== null && (float) $record->allowance_override > 0) {
                $lines[] = ['kind' => 'earning', 'label' => 'Phụ cấp (điều chỉnh trước đây)', 'amount' => (float) $record->allowance_override];
            }
            if ((float) $record->other_bonus > 0) {
                $lines[] = ['kind' => 'earning', 'label' => 'Thưởng khác (điều chỉnh trước đây)', 'amount' => (float) $record->other_bonus];
            }
            if ((float) $record->other_deduction > 0) {
                $lines[] = ['kind' => 'deduction', 'label' => 'Khấu trừ khác (điều chỉnh trước đây)', 'amount' => (float) $record->other_deduction];
            }
            if ($lines !== []) {
                DB::table('payroll_records')->where('id', $record->id)->update([
                    'manual_lines' => json_encode($lines, JSON_UNESCAPED_UNICODE),
                    'allowance_override' => null,
                    'other_bonus' => 0,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->dropColumn([
                'employee_type', 'salary_role', 'teaching_sessions', 'retention_base_students', 'retention_students',
                'retention_tier', 'kpi_source', 'kpi_manual_amount', 'kpi_score', 'foreign_session_pay',
                'commission_deferred', 'commission_percent', 'commission_closed_count', 'union_deduction',
                'manual_lines', 'calculation_details',
            ]);
        });
    }
};
