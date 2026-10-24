<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Syllabus của lớp (docs/kho-syllabus.md §7.1): bản đè theo buổi — chỉ các trường nội dung bị sửa riêng cho lớp, kèm lý do
 * ("Chỉnh sửa thủ công = Có"). Không sao chép syllabus: sửa bản gốc vẫn tới các trường chưa bị đè.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_lesson_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('syllabus_lesson_id')->constrained('syllabus_lessons')->cascadeOnDelete();
            $table->json('data');
            $table->text('reason');
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['class_id', 'syllabus_lesson_id']);
            $table->index('syllabus_lesson_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_lesson_overrides');
    }
};
