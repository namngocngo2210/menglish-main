<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mockup "Nhập điểm mini test": chọn Unit + điểm 4 kỹ năng Nghe / Nói / Đọc / Viết (điểm tổng = trung bình).
        Schema::table('mini_test_scores', function (Blueprint $table) {
            $table->foreignId('syllabus_unit_id')->nullable()->after('user_id')->constrained('syllabus_units')->nullOnDelete();
            $table->json('skill_scores')->nullable()->after('max_score');
        });
    }

    public function down(): void
    {
        Schema::table('mini_test_scores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('syllabus_unit_id');
            $table->dropColumn('skill_scores');
        });
    }
};
