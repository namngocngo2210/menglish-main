<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Q3 Part-time: lương = số buổi × đơn giá RIÊNG từng GV theo BUỔI. Bảng đơn giá riêng giữ nguyên
     * lịch sử theo ngày hiệu lực; mỗi phiên bản có đơn vị: 'session' (đ/buổi — mặc định từ nay) hoặc
     * 'hour' (đ/giờ — các dòng cũ). Số tiền vẫn nằm ở cột hourly_rate (tên cũ, nghĩa là "đơn giá").
     */
    public function up(): void
    {
        Schema::table('teacher_hourly_rates', function (Blueprint $table) {
            $table->string('rate_unit', 10)->default('hour')->after('hourly_rate');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_hourly_rates', function (Blueprint $table) {
            $table->dropColumn('rate_unit');
        });
    }
};
