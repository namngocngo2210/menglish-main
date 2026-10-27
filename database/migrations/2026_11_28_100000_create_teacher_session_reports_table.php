<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Báo cáo buổi học của giáo viên chính (chủ dự án 27/10/2026): mỗi buổi một bản, GV chính bấm Nộp; chỉ để xem, không duyệt,
 * không ảnh. Tách khỏi báo cáo trực lớp của trợ giảng (class_reports). App\Models\TeacherSessionReport.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_session_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('class_session_id')->unique()->constrained('class_sessions')->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('content'); // Nội dung đã dạy
            $table->text('class_situation')->nullable(); // Tình hình lớp
            $table->json('attention')->nullable(); // Học sinh cần chú ý: [{student_id, reason, action}]
            $table->text('proposal')->nullable(); // Đề xuất / cần hỗ trợ
            $table->timestamp('submitted_at'); // Lần nộp đầu (tính trễ hạn)
            $table->timestamps();
            $table->index(['class_id', 'class_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_session_reports');
    }
};
