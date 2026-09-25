<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mockup "Quản lý tài liệu giáo trình" / "Xem tài liệu giáo trình":
 *  - tài liệu gắn đúng Chặng của giáo trình (syllabus_documents.stage_id; `stage_name` cũ nhập tự do giữ để hiển thị dữ liệu cũ);
 *  - giáo viên "Đánh dấu đã xem" tài liệu (syllabus_document_views, 1 dòng / người / tài liệu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('syllabus_documents', function (Blueprint $table) {
            $table->foreignId('stage_id')->nullable()->after('curriculum_id')->constrained('syllabus_stages')->nullOnDelete();
        });

        Schema::create('syllabus_document_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('syllabus_documents')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('syllabus_document_views');
        Schema::table('syllabus_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stage_id');
        });
    }
};
