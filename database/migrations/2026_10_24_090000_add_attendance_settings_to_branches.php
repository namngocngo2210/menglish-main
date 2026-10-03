<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cài đặt chấm công theo cơ sở (Cài đặt → Cơ sở & chi nhánh → "Chấm công"): toạ độ cơ sở, bán kính được chấm (mét),
 * giờ làm việc (vào / ra) và số phút cho phép đến muộn. Nhân sự chỉ chấm công được khi GPS điện thoại nằm trong bán kính
 * của cơ sở mình làm việc. Chạy lại an toàn (MySQL không rollback DDL khi deploy lỗi giữa chừng).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            if (! Schema::hasColumn('branches', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('phone');
            }
            if (! Schema::hasColumn('branches', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
            if (! Schema::hasColumn('branches', 'checkin_radius')) {
                $table->unsignedInteger('checkin_radius')->default(100)->after('longitude');
            }
            if (! Schema::hasColumn('branches', 'work_start_time')) {
                $table->time('work_start_time')->nullable()->after('checkin_radius');
            }
            if (! Schema::hasColumn('branches', 'work_end_time')) {
                $table->time('work_end_time')->nullable()->after('work_start_time');
            }
            if (! Schema::hasColumn('branches', 'late_grace_minutes')) {
                $table->unsignedSmallInteger('late_grace_minutes')->default(0)->after('work_end_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            foreach (['latitude', 'longitude', 'checkin_radius', 'work_start_time', 'work_end_time', 'late_grace_minutes'] as $column) {
                if (Schema::hasColumn('branches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
