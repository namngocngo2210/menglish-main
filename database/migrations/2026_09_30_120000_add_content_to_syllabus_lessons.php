<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mockup "Soạn syllabus theo chặng": mỗi buổi có "Mục tiêu", "Nội dung bài học chính", "Bài tập về nhà".
 * Nội dung chính (hoạt động trên lớp) chưa có cột riêng → thêm syllabus_lessons.content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('syllabus_lessons', function (Blueprint $table) {
            $table->text('content')->nullable()->after('objectives');
        });
    }

    public function down(): void
    {
        Schema::table('syllabus_lessons', function (Blueprint $table) {
            $table->dropColumn('content');
        });
    }
};
