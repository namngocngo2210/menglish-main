<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sửa logic lương theo ticket TK-0009…0015 (30/10/2026):
 * - users.monthly_allowance: phụ cấp theo hợp đồng (đ/tháng), trả theo ngày công như lương cơ bản.
 * - payroll_records.base_salary_paid: lương cơ bản thực trả theo ngày công (base_salary giữ mức hợp đồng; NULL = phiếu tính
 *   trước thay đổi này). contract_allowance: phụ cấp hợp đồng thực trả theo ngày công.
 * - payroll_records.late_deduction: đi muộn chấm công hằng ngày cộng dồn cả tháng × công giờ; missing_punch_deduction: phạt
 *   quên chấm vào / ra mỗi lần. Đi muộn không lập biên bản từng ngày nữa → hủy các biên bản tự động đi muộn của ngày công
 *   còn chờ giải trình / đã giải trình.
 * Chạy lại an toàn (MySQL không rollback DDL khi deploy lỗi giữa chừng).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'monthly_allowance')) {
                $table->decimal('monthly_allowance', 15, 2)->default(0);
            }
        });

        Schema::table('payroll_records', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_records', 'base_salary_paid')) {
                $table->decimal('base_salary_paid', 15, 2)->nullable()->after('base_salary');
            }
            if (! Schema::hasColumn('payroll_records', 'contract_allowance')) {
                $table->decimal('contract_allowance', 15, 2)->default(0)->after('allowance');
            }
            if (! Schema::hasColumn('payroll_records', 'late_deduction')) {
                $table->decimal('late_deduction', 15, 2)->default(0)->after('penalty_deduction');
            }
            if (! Schema::hasColumn('payroll_records', 'missing_punch_deduction')) {
                $table->decimal('missing_punch_deduction', 15, 2)->default(0)->after('late_deduction');
            }
        });

        $autoLate = DB::table('staff_attendances')->whereNotNull('penalty_id')->pluck('penalty_id');
        if ($autoLate->isNotEmpty()) {
            DB::table('penalties')->whereIn('id', $autoLate->all())->whereIn('status', ['pending', 'explained'])->update([
                'status' => 'cancelled',
                'decided_at' => now(),
                'decision_note' => 'Tự hủy: đi muộn chấm công hằng ngày cộng dồn cả tháng và trừ trên bảng lương, không lập biên bản từng ngày.',
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            foreach (['base_salary_paid', 'contract_allowance', 'late_deduction', 'missing_punch_deduction'] as $column) {
                if (Schema::hasColumn('payroll_records', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'monthly_allowance')) {
                $table->dropColumn('monthly_allowance');
            }
        });
    }
};
