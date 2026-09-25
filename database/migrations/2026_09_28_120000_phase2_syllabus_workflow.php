<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Giáo trình: thông tin chặng của giáo trình, kho tài liệu có file thật,
 * đề xuất sửa giáo trình và lưu kết quả xử lý yêu cầu giãn tiến độ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('syllabus_curriculums', function (Blueprint $table) {
            $table->string('stage_name')->nullable()->after('title');
            $table->string('unlock_policy', 30)->nullable()->after('stage_name'); // weekly, after_big_test, manual
            $table->string('overview_link', 500)->nullable()->after('unlock_policy');
        });

        // Tài liệu giáo trình: file lưu ở disk private, chỉ tải qua route có kiểm tra quyền xem.
        Schema::create('syllabus_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_id')->constrained('syllabus_curriculums')->cascadeOnDelete();
            $table->string('title');
            $table->string('stage_name')->nullable();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('extension', 10);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->boolean('visible_to_teachers')->default(false);
            $table->boolean('visible_to_assistants')->default(false);
            $table->boolean('downloadable')->default(false);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('syllabus_change_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_id')->constrained('syllabus_curriculums')->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('syllabus_units')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // Người đề xuất
            $table->string('proposal_type')->nullable();
            $table->text('old_content')->nullable();
            $table->text('new_content');
            $table->text('reason')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('status', 20)->default('pending'); // pending, approved, rejected
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::table('syllabus_adjustment_requests', function (Blueprint $table) {
            $table->unsignedSmallInteger('extra_sessions')->nullable()->after('reason');
            $table->text('rejection_reason')->nullable()->after('status');
            $table->timestamp('reviewed_at')->nullable()->after('rejection_reason');
            $table->text('applied_note')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('syllabus_adjustment_requests', function (Blueprint $table) {
            $table->dropColumn(['extra_sessions', 'rejection_reason', 'reviewed_at', 'applied_note']);
        });
        Schema::dropIfExists('syllabus_change_proposals');
        Schema::dropIfExists('syllabus_documents');
        Schema::table('syllabus_curriculums', function (Blueprint $table) {
            $table->dropColumn(['stage_name', 'unlock_policy', 'overview_link']);
        });
    }
};
