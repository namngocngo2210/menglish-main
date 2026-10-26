<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bỏ đồng bộ quyền xem Google Drive của syllabus: PPT của buổi tải lên app và trình chiếu bằng iframe (syllabus_lesson_slides).
 * Quyền đã mở trên Drive không bị thu hồi tự động — Học thuật tự gỡ trên Drive nếu cần.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('syllabus_drive_grants');
    }

    public function down(): void
    {
        Schema::create('syllabus_drive_grants', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('file_id', 128);
            $table->string('file_kind', 10)->default('file');
            $table->string('permission_id', 128)->nullable();
            $table->string('status', 20)->default('pending');
            $table->boolean('pre_existing')->default(false);
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('grant_requested_at')->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('failure_notified_at')->nullable();
            $table->timestamps();
            $table->unique(['email', 'file_id']);
            $table->index('status');
        });
    }
};
