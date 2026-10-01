<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Đi muộn / về sớm của GV (ngưỡng 15 phút): số phút muộn / sớm, có báo trước hay không.
     * Cách tính tiền xem TeacherTimesheet::payOutcome().
     */
    public function up(): void
    {
        Schema::table('teacher_timesheets', function (Blueprint $table) {
            $table->unsignedSmallInteger('late_minutes')->default(0)->after('hours');
            $table->unsignedSmallInteger('early_leave_minutes')->default(0)->after('late_minutes');
            $table->boolean('late_notified')->default(false)->after('early_leave_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_timesheets', function (Blueprint $table) {
            $table->dropColumn(['late_minutes', 'early_leave_minutes', 'late_notified']);
        });
    }
};
