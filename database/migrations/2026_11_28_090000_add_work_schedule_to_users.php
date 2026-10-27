<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lịch làm việc riêng của nhân sự (chấm công): giờ vào / ra riêng (ca chiều, ca tối… thay giờ làm việc chung của cơ sở)
 * và ngày nghỉ cố định trong tuần (1 = Thứ 2 … 7 = Chủ nhật). Để trống = theo giờ cơ sở, làm mọi ngày.
 * Chạy lại an toàn (MySQL không rollback DDL khi deploy lỗi giữa chừng).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'work_start_time')) {
                $table->time('work_start_time')->nullable();
            }
            if (! Schema::hasColumn('users', 'work_end_time')) {
                $table->time('work_end_time')->nullable();
            }
            if (! Schema::hasColumn('users', 'weekly_days_off')) {
                $table->json('weekly_days_off')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['work_start_time', 'work_end_time', 'weekly_days_off'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
