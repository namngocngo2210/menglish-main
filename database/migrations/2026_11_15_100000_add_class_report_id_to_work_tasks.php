<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task follow-up tạo từ báo cáo trực lớp của trợ giảng (chủ dự án 17/10/2026): `work_tasks.class_report_id` = báo cáo tạo ra việc,
 * để việc chưa xong hiện lại ở báo cáo trực lớp sau của cùng lớp và người quản lý thấy việc kèm báo cáo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_tasks', function (Blueprint $table) {
            $table->foreignId('class_report_id')->nullable()->after('report_section')->constrained('class_reports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('class_report_id');
        });
    }
};
