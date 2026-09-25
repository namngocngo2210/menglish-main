<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mockup "Lịch dự kiến Big Test" (Cổng GV) + "Nhắc lịch Big Test" (Admin): GV chính đặt ngày dự kiến thi
 * Big Test cuối chặng đang mở; màn nhắc lịch liệt kê chặng sắp đến hạn thi (≤ 7 ngày) mà đề chưa được duyệt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('syllabus_assignments', function (Blueprint $table) {
            $table->date('expected_big_test_date')->nullable()->after('deadline');
        });
    }

    public function down(): void
    {
        Schema::table('syllabus_assignments', function (Blueprint $table) {
            $table->dropColumn('expected_big_test_date');
        });
    }
};
