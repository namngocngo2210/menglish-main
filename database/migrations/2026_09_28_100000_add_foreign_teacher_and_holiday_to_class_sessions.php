<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // GVNN của từng buổi (trước đây chỉ lưu teacher_id = GV chính ?? GVNN nên không kiểm tra
        // được trùng lịch GVNN khi lớp có cả GV chính). holiday_id / rescheduled_from_id đánh dấu
        // buổi bị hủy vì ngày nghỉ lễ thêm sau và buổi học bù tương ứng.
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->foreignId('foreign_teacher_id')->nullable()->after('teacher_id')->constrained('users')->nullOnDelete();
            $table->foreignId('holiday_id')->nullable()->after('notes')->constrained('holidays')->nullOnDelete();
            $table->foreignId('rescheduled_from_id')->nullable()->after('holiday_id')->constrained('class_sessions')->nullOnDelete();
            $table->index(['foreign_teacher_id', 'date'], 'class_sessions_foreign_teacher_date_idx');
        });

        // Backfill GVNN từ lớp cho các buổi chính khóa/học bù (buổi phụ đạo không có GVNN).
        DB::table('class_sessions')
            ->where('type', '!=', 'support')
            ->update([
                'foreign_teacher_id' => DB::raw('(select classes.foreign_teacher_id from classes where classes.id = class_sessions.class_id)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropIndex('class_sessions_foreign_teacher_date_idx');
            $table->dropConstrainedForeignId('rescheduled_from_id');
            $table->dropConstrainedForeignId('holiday_id');
            $table->dropConstrainedForeignId('foreign_teacher_id');
        });
    }
};
