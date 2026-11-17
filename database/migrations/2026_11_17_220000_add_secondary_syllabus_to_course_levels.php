<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TK-2026-0136 (EP5-TD-05): một trình độ gắn tối đa 2 Syllabus. Giữ syllabus_curriculum_id làm Syllabus chính
        // (mọi nơi đang đọc cột này không đổi), thêm cột thứ hai — dữ liệu cũ giữ nguyên.
        Schema::table('course_levels', function (Blueprint $table) {
            $table->foreignId('secondary_syllabus_curriculum_id')->nullable()->after('syllabus_curriculum_id')
                ->constrained('syllabus_curriculums')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('course_levels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('secondary_syllabus_curriculum_id');
        });
    }
};
