<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Đơn vị gõ tay trước đây → khóa chuẩn (KpiCriterion::UNITS). */
    private const UNIT_KEYS = [
        'lần' => 'lan', 'hồ sơ' => 'ho_so', 'case' => 'case', 'sai sót' => 'sai_sot', 'sự cố' => 'su_co',
        'lớp' => 'lop', 'buổi' => 'buoi', 'học viên' => 'hoc_vien', 'hv' => 'hoc_vien', 'bài' => 'bai', 'ngày' => 'ngay',
    ];

    /**
     * Tiêu chí KPI theo vai trò cố định: mỗi tiêu chí thuộc một vai trò (bộ hiện có là của Học vụ).
     * Đơn vị đếm chuyển sang khóa chọn từ danh sách để số liệu KPI cùng chuẩn.
     */
    public function up(): void
    {
        Schema::table('kpi_criteria', function (Blueprint $table) {
            $table->string('role', 50)->default('academic_staff')->after('id')->index();
        });

        foreach (self::UNIT_KEYS as $text => $key) {
            DB::table('kpi_criteria')->whereRaw('LOWER(TRIM(unit)) = ?', [$text])->update(['unit' => $key]);
        }
    }

    public function down(): void
    {
        foreach (array_flip(array_unique(self::UNIT_KEYS)) as $key => $text) {
            DB::table('kpi_criteria')->where('unit', $key)->update(['unit' => $text]);
        }
        Schema::table('kpi_criteria', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }
};
