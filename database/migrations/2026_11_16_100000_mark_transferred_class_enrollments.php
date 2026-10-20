<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Chuyển lớp trước đây chỉ ghi ngày rời lớp (left_at) cho lớp chính cũ, trạng thái vẫn "pending/completed" nên học viên
 * còn nằm trong danh sách + sĩ số lớp cũ. Đánh dấu các lượt đó "transferred" (Đã chuyển lớp): đã có ngày rời lớp và
 * không phải lớp chính hiện tại. Sổ buổi không đổi (vẫn tính theo left_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('class_enrollments')
            ->whereIn('status', ['pending', 'completed'])
            ->whereNotNull('left_at')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('students')
                ->whereColumn('students.id', 'class_enrollments.student_id')
                ->whereColumn('students.current_class_id', 'class_enrollments.class_id'))
            ->update(['status' => 'transferred']);
    }

    public function down(): void
    {
        DB::table('class_enrollments')->where('status', 'transferred')->update(['status' => 'completed']);
    }
};
