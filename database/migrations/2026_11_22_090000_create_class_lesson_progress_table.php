<?php

use App\Services\Syllabus\ProgressBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tiến độ chặng theo nút "Đã dạy" của GV (docs/kho-syllabus.md §8.1): `lesson` = đã dạy xong buổi syllabus, `extra` = dùng 1
 * buổi giãn đã duyệt. Lớp đang chạy được đánh dấu sẵn các buổi đã học (cách đếm cũ) để không quay về buổi 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('syllabus_assignment_id')->constrained('syllabus_assignments')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('syllabus_lesson_id')->nullable()->constrained('syllabus_lessons')->nullOnDelete();
            $table->foreignId('class_session_id')->nullable()->constrained('class_sessions')->nullOnDelete();
            $table->string('kind', 10)->default('lesson');
            $table->text('pace_note')->nullable();
            $table->foreignId('taught_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('taught_at');
            $table->timestamps();
            $table->unique(['syllabus_assignment_id', 'syllabus_lesson_id']);
            $table->unique(['syllabus_assignment_id', 'class_session_id']);
            $table->index(['class_id', 'taught_at']);
        });

        app(ProgressBackfill::class)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('class_lesson_progress');
    }
};
