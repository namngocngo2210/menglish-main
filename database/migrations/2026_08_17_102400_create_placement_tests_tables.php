<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('placement_tests', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // TEST-01
            $table->string('title'); // Đề Test 4 Kỹ Năng - Standard 2026
            $table->string('target_level')->default('Tổng hợp A1 - B2');
            $table->integer('duration_minutes')->default(45);
            $table->integer('questions_count')->default(40);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('placement_test_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('placement_test_id')->constrained('placement_tests')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('crm_customers')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->string('candidate_name');
            $table->string('candidate_phone');
            $table->string('candidate_email')->nullable();
            $table->decimal('listening_score', 4, 1)->default(0);
            $table->decimal('reading_score', 4, 1)->default(0);
            $table->decimal('writing_score', 4, 1)->default(0);
            $table->decimal('speaking_score', 4, 1)->default(0);
            $table->decimal('overall_score', 4, 1)->default(0);
            $table->string('cefr_level', 10)->default('B1'); // A1, A2, B1, B2, C1
            $table->text('writing_content')->nullable();
            $table->string('speaking_audio_url')->nullable();
            $table->string('recommended_course')->nullable();
            $table->text('teacher_comments')->nullable();
            $table->foreignId('grader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 50)->default('graded'); // pending, graded, notified
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('placement_test_submissions');
        Schema::dropIfExists('placement_tests');
    }
};
