<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buổi bị xóa mềm nhả số buổi bằng session_no = -id (SyllabusLesson::booted). Cột cũ là unsigned smallint nên MariaDB
 * từ chối số âm → không xóa được buổi / giáo trình. Đổi sang integer có dấu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('syllabus_lessons', function (Blueprint $table) {
            $table->integer('session_no')->change();
        });
    }

    // Chỉ chạy được khi chưa có buổi xóa mềm nào (session_no âm).
    public function down(): void
    {
        Schema::table('syllabus_lessons', function (Blueprint $table) {
            $table->unsignedSmallInteger('session_no')->change();
        });
    }
};
