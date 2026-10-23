<?php

use App\Models\SyllabusCurriculum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Giáo trình xóa mềm trước khi có SyllabusCurriculum::booted() softDeleted vẫn giữ mã → không tạo/import lại được cùng mã.
 * Nhả mã cho các bản ghi đó (code#deleted-id). Không đảo ngược.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('syllabus_curriculums')->whereNotNull('deleted_at')->where('code', 'not like', '%#deleted-%')
            ->orderBy('id')->get(['id', 'code'])
            ->each(fn ($row) => DB::table('syllabus_curriculums')->where('id', $row->id)
                ->update(['code' => SyllabusCurriculum::releasedCode($row->code, $row->id)]));
    }

    public function down(): void
    {
        // Không khôi phục mã cũ: có thể đã có giáo trình mới dùng lại mã đó.
    }
};
