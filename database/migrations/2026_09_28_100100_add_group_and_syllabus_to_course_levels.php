<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nhóm trình độ (KIDS / TEENS / IELTS...) và giáo trình gắn kèm theo mockup "Cấu hình Trình độ & Syllabus".
        Schema::table('course_levels', function (Blueprint $table) {
            $table->string('level_group', 50)->nullable()->after('name');
            $table->foreignId('syllabus_curriculum_id')->nullable()->after('lessons_count')
                ->constrained('syllabus_curriculums')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('course_levels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('syllabus_curriculum_id');
            $table->dropColumn('level_group');
        });
    }
};
