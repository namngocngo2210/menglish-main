<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quyền xem file Google Drive do hệ thống mở theo chặng lớp đang học (docs/kho-syllabus.md §7.4): 1 dòng / (email, file).
 * `pre_existing` = người đã có quyền từ trước (không bao giờ thu hồi).
 */
return new class extends Migration
{
    public function up(): void
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
            // Đã gửi yêu cầu tạo quyền (kể cả khi phản hồi lỗi / timeout): quyền tìm thấy sau đó là của hệ thống, không phải có từ trước.
            $table->timestamp('grant_requested_at')->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('failure_notified_at')->nullable();
            $table->timestamps();
            $table->unique(['email', 'file_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('syllabus_drive_grants');
    }
};
