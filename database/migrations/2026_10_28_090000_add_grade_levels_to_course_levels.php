<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cấp độ test đầu vào (Mẫu giáo, Lớp 1..9) ứng với trình độ lớp: xếp lớp lọc lớp theo cấp độ của học viên.
        Schema::table('course_levels', function (Blueprint $table) {
            $table->json('grade_levels')->nullable()->after('level_group');
        });
    }

    public function down(): void
    {
        Schema::table('course_levels', function (Blueprint $table) {
            $table->dropColumn('grade_levels');
        });
    }
};
