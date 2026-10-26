<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bài giảng PowerPoint tải lên cho từng buổi (docs/kho-syllabus.md §7.4): 1 file / (buổi, loại PPT GV | PPT GVNN), trình chiếu
 * trong app bằng trình xem Office Online (iframe) thay cho link Google Drive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('syllabus_lesson_slides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('syllabus_lesson_id')->constrained('syllabus_lessons')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('file_path');
            $table->string('original_name');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['syllabus_lesson_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('syllabus_lesson_slides');
    }
};
