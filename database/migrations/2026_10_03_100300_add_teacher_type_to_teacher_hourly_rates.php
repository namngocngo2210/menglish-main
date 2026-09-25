<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Loại giáo viên" của từng phiên bản đơn giá (mockup epic-7/cau-hinh-don-gia-giao-vien):
     * parttime / fulltime / foreign (GVNN) / assistant (Trợ giảng). Chỉ để hiển thị & đối soát —
     * công thức lương vẫn theo loại nhân sự suy từ vai trò/hợp đồng (Q3).
     */
    public function up(): void
    {
        Schema::table('teacher_hourly_rates', function (Blueprint $table) {
            $table->string('teacher_type', 20)->nullable()->after('rate_unit');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_hourly_rates', function (Blueprint $table) {
            $table->dropColumn('teacher_type');
        });
    }
};
