<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Phân biệt buổi học chính khóa với buổi phụ đạo/học bù để việc xếp lại TKB
        // không xóa nhầm buổi phụ đạo đang giữ chỗ giáo viên/phòng.
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->string('type', 20)->default('regular')->after('shift_name'); // regular, support, makeup
            $table->index(['class_id', 'date', 'status'], 'class_sessions_class_date_status_idx');
            $table->index(['date', 'start_time'], 'class_sessions_date_start_idx');
        });

        DB::table('class_sessions')
            ->whereIn('id', DB::table('support_sessions')->whereNotNull('class_session_id')->select('class_session_id'))
            ->update(['type' => 'support']);

        // Slot 2 là tùy chọn: để trống thì không sinh buổi học.
        Schema::table('class_schedule_configs', function (Blueprint $table) {
            $table->string('slot2_day')->nullable()->default(null)->change();
            $table->string('slot2_start')->nullable()->default(null)->change();
            $table->string('slot2_end')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        DB::table('class_schedule_configs')->whereNull('slot2_day')
            ->update(['slot2_day' => 'Thứ 7', 'slot2_start' => '18:00', 'slot2_end' => '19:30']);

        Schema::table('class_schedule_configs', function (Blueprint $table) {
            $table->string('slot2_day')->nullable(false)->default('Thứ 7')->change();
            $table->string('slot2_start')->nullable(false)->default('18:00')->change();
            $table->string('slot2_end')->nullable(false)->default('19:30')->change();
        });

        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropIndex('class_sessions_class_date_status_idx');
            $table->dropIndex('class_sessions_date_start_idx');
            $table->dropColumn('type');
        });
    }
};
