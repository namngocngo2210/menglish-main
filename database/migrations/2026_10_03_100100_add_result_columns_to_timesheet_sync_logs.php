<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lịch sử đồng bộ chấm công theo mockup epic-7/lich-su-dong-bo-cham-cong: mỗi đợt có Tổng số dòng
     * (records_count), Thành công (matched_count), Lỗi, Bỏ qua (dòng của nhân sự đã chốt kỳ lương),
     * lỗi hệ thống (mã + nội dung) và danh sách dòng lỗi (Mã NV, Tên, Mã lỗi, Nội dung) để xuất Excel.
     */
    public function up(): void
    {
        Schema::table('timesheet_sync_logs', function (Blueprint $table) {
            $table->string('source', 50)->nullable()->after('device_ip');
            $table->integer('failed_count')->default(0)->after('matched_count');
            $table->integer('skipped_count')->default(0)->after('failed_count');
            $table->string('error_code', 100)->nullable()->after('status');
            $table->text('error_message')->nullable()->after('error_code');
            $table->json('error_rows')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('timesheet_sync_logs', function (Blueprint $table) {
            $table->dropColumn(['source', 'failed_count', 'skipped_count', 'error_code', 'error_message', 'error_rows']);
        });
    }
};
