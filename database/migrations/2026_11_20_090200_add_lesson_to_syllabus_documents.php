<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tài liệu gắn theo Buổi (docs/kho-syllabus.md §3.2): có syllabus_lesson_id → tài liệu buổi (stage_id = chặng của buổi);
 * chỉ stage_id → tài liệu chặng; không có cả hai → tài liệu chung của giáo trình.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('syllabus_documents', function (Blueprint $table) {
            $table->foreignId('syllabus_lesson_id')->nullable()->after('stage_id')->constrained('syllabus_lessons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('syllabus_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('syllabus_lesson_id');
        });
    }
};
