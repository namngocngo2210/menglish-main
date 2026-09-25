<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NULL = không chỉ định đơn giá riêng cho ca dạy → bảng lương dùng
     * users.hourly_rate rồi mới tới mức mặc định. Default 300.000 cũ khiến
     * fallback không bao giờ chạy.
     */
    public function up(): void
    {
        Schema::table('teacher_timesheets', function (Blueprint $table) {
            $table->decimal('hourly_rate', 15, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('teacher_timesheets', function (Blueprint $table) {
            $table->decimal('hourly_rate', 15, 2)->default(300000)->change();
        });
    }
};
