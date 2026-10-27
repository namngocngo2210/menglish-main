<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Báo cáo buổi: cột "Thực hành trên lớp" do giáo viên tự đặt tên (danh sách tên cột, theo thứ tự hiển thị).
 * Giá trị từng học sinh lưu trong nhận xét buổi (academic_records module teacher_remarks, khóa practice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_session_reports', function (Blueprint $table) {
            $table->json('practice_columns')->nullable()->after('proposal');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_session_reports', function (Blueprint $table) {
            $table->dropColumn('practice_columns');
        });
    }
};
