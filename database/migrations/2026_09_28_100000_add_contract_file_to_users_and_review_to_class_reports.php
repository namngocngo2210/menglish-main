<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 (nền tảng):
 * - users.contract_file_path: file hợp đồng lao động lưu ở disk riêng tư (local), tải qua route có kiểm tra quyền.
 * - class_reports.rejection_reason: lý do trả về khi GV chính / Học vụ từ chối báo cáo trực lớp.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'contract_file_path')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('contract_file_path')->nullable()->after('contract_end_date');
            });
        }

        if (Schema::hasTable('class_reports') && ! Schema::hasColumn('class_reports', 'rejection_reason')) {
            Schema::table('class_reports', function (Blueprint $table) {
                $table->text('rejection_reason')->nullable()->after('approved_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'contract_file_path')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('contract_file_path');
            });
        }

        if (Schema::hasTable('class_reports') && Schema::hasColumn('class_reports', 'rejection_reason')) {
            Schema::table('class_reports', function (Blueprint $table) {
                $table->dropColumn('rejection_reason');
            });
        }
    }
};
