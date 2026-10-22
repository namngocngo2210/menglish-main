<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loại hành động của task follow-up tạo từ báo cáo trực lớp (Gọi phụ huynh, Xếp buổi bù…, xem WorkTask::FOLLOW_UP_ACTIONS).
 * Việc cũ / việc không phải follow-up để NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_tasks', function (Blueprint $table) {
            $table->string('follow_up_action', 32)->nullable()->after('class_report_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_tasks', function (Blueprint $table) {
            $table->dropColumn('follow_up_action');
        });
    }
};
