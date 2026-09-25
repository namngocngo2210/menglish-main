<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - Link riêng phần Speaking của đề (GV chỉ xem phần Speaking sau khi phân phối; link đề đầy đủ chỉ Học thuật xem).
     * - Nhắc lịch Big Test theo ngày dự kiến GV đặt cho chặng chưa có đợt thi: lưu ngày đã nhắc để chạy lại không nhắc trùng.
     */
    public function up(): void
    {
        Schema::table('big_test_orders', function (Blueprint $table) {
            $table->string('speaking_link', 500)->nullable()->after('test_link');
        });
        Schema::table('big_tests', function (Blueprint $table) {
            $table->string('speaking_url', 500)->nullable()->after('content_url');
        });
        Schema::table('syllabus_assignments', function (Blueprint $table) {
            $table->date('big_test_reminded_for')->nullable()->after('expected_big_test_date');
        });
    }

    public function down(): void
    {
        Schema::table('syllabus_assignments', function (Blueprint $table) {
            $table->dropColumn('big_test_reminded_for');
        });
        Schema::table('big_tests', function (Blueprint $table) {
            $table->dropColumn('speaking_url');
        });
        Schema::table('big_test_orders', function (Blueprint $table) {
            $table->dropColumn('speaking_link');
        });
    }
};
