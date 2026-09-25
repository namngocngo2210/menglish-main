<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mockup "Giao bài tập về nhà": gắn buổi học, hạn nộp có giờ, ghi chú nhắc cả lớp, tài liệu tham khảo
        // (YouTube, file nghe, Quizizz) và hạng mục bài tập kèm yêu cầu chi tiết.
        Schema::table('homeworks', function (Blueprint $table) {
            $table->foreignId('class_session_id')->nullable()->after('class_id')->constrained('class_sessions')->nullOnDelete();
            $table->dateTime('due_at')->nullable()->after('due_date');
            $table->text('class_note')->nullable()->after('description');
            $table->string('youtube_url', 500)->nullable()->after('class_note');
            $table->string('quizizz_url', 500)->nullable()->after('youtube_url');
            $table->string('audio_path', 500)->nullable()->after('quizizz_url');
            $table->json('items')->nullable()->after('audio_path');
        });
    }

    public function down(): void
    {
        Schema::table('homeworks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('class_session_id');
            $table->dropColumn(['due_at', 'class_note', 'youtube_url', 'quizizz_url', 'audio_path', 'items']);
        });
    }
};
