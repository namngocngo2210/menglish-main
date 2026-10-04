<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dự án học thuật (soạn sách, xây chương trình…): dự án → thành viên, mốc tiến độ (hạn + khối lượng + người nhận),
 * cập nhật tiến độ của thành viên (khối lượng đã xong, link sản phẩm, khó khăn) và phản hồi của Học thuật.
 * Tên index / khóa ngoại tự đặt ngắn (MySQL giới hạn 64 ký tự).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_projects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique('acad_projects_code_unique');
            $table->string('name');
            $table->string('type', 20)->default('book');
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users', 'id', 'acad_projects_owner_fk')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('deadline')->nullable();
            $table->string('status', 20)->default('planning')->index('acad_projects_status_idx');
            $table->text('kickoff_notes')->nullable();
            $table->string('kickoff_link', 500)->nullable();
            $table->timestamp('plan_locked_at')->nullable();
            $table->foreignId('plan_locked_by')->nullable()->constrained('users', 'id', 'acad_projects_locker_fk')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'acad_projects_creator_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('academic_project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_project_id')->constrained('academic_projects', 'id', 'acad_members_project_fk')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users', 'id', 'acad_members_user_fk')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['academic_project_id', 'user_id'], 'acad_members_unique');
        });

        Schema::create('academic_project_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_project_id')->constrained('academic_projects', 'id', 'acad_milestones_project_fk')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users', 'id', 'acad_milestones_assignee_fk')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('due_date');
            $table->decimal('target_quantity', 10, 2)->default(1);
            $table->string('unit', 30)->default('phần việc');
            $table->decimal('done_quantity', 10, 2)->default(0);
            $table->string('status', 20)->default('todo');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['academic_project_id', 'due_date'], 'acad_milestones_project_due_idx');
            $table->index(['status', 'due_date'], 'acad_milestones_status_due_idx');
        });

        Schema::create('academic_project_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_project_id')->constrained('academic_projects', 'id', 'acad_updates_project_fk')->cascadeOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained('academic_project_milestones', 'id', 'acad_updates_milestone_fk')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users', 'id', 'acad_updates_user_fk')->nullOnDelete();
            $table->decimal('quantity_done', 10, 2)->nullable();
            $table->text('content');
            $table->text('difficulties')->nullable();
            $table->json('links')->nullable();
            $table->boolean('marks_complete')->default(false);
            $table->text('response')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users', 'id', 'acad_updates_responder_fk')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->index(['academic_project_id', 'created_at'], 'acad_updates_project_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_project_updates');
        Schema::dropIfExists('academic_project_milestones');
        Schema::dropIfExists('academic_project_members');
        Schema::dropIfExists('academic_projects');
    }
};
