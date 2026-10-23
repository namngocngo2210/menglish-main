<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Buổi học xóa mềm trước bản sửa MariaDB (8c782125) còn giữ session_no dương (hook softDeleted không ghi được -id vào cột
 * unsigned) → chặn UNIQUE (curriculum_id, session_no) khi thêm buổi / import chặng. Nhả số: session_no = -id.
 * Không hoàn tác (số cũ của buổi đã xóa không còn ý nghĩa).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('syllabus_lessons')->whereNotNull('deleted_at')->where('session_no', '>', 0)->update(['session_no' => DB::raw('-id')]);
    }

    public function down(): void
    {
        // Không hoàn tác được.
    }
};
