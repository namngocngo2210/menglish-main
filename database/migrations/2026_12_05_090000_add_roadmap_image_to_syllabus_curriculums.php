<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ảnh lộ trình tổng quát của giáo trình (cổng học sinh hiển thị đúng 1 ảnh này; TK-2026-0044).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('syllabus_curriculums', function (Blueprint $table) {
            $table->string('roadmap_image_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('syllabus_curriculums', function (Blueprint $table) {
            $table->dropColumn('roadmap_image_path');
        });
    }
};
