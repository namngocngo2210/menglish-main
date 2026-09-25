<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mockup "Đề xuất sửa giáo trình": giáo viên chọn Buổi học cần sửa (Q4 — Giáo trình → Chặng → Unit → Buổi).
 * Đề xuất cũ chỉ có unit_id vẫn giữ nguyên.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('syllabus_change_proposals', function (Blueprint $table) {
            $table->foreignId('lesson_id')->nullable()->after('unit_id')->constrained('syllabus_lessons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('syllabus_change_proposals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lesson_id');
        });
    }
};
