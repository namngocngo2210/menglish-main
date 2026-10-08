<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Học thuật kiêm nhiệm giảng dạy: option trên hồ sơ nhân sự (users.academic_teaching). Bật thì phiếu lương Học thuật
     * mở thêm phần lương đứng lớp (% học phí theo buổi đã dạy) và KPI kiêm nhiệm (giữ HS × bậc, như GV part-time).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('academic_teaching')->default(false)->after('hourly_rate');
        });

        Schema::table('payroll_records', function (Blueprint $table) {
            $table->boolean('teaching_concurrent')->default(false)->after('teaching_salary');
            $table->decimal('teaching_kpi_bonus', 15, 2)->default(0)->after('teaching_concurrent');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->dropColumn(['teaching_concurrent', 'teaching_kpi_bonus']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('academic_teaching');
        });
    }
};
