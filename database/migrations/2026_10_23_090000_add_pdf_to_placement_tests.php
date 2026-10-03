<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đề test đầu vào tạo từ file PDF: thí sinh xem nguyên file PDF và trả lời trên phiếu đáp án (câu hỏi vẫn lưu ở cột questions).
 * pdf_path: đường dẫn file trên disk public; audio_url: file nghe chung của cả đề (phần Listening).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('placement_tests', function (Blueprint $table) {
            $table->string('pdf_path')->nullable()->after('questions');
            $table->string('audio_url', 500)->nullable()->after('pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('placement_tests', function (Blueprint $table) {
            $table->dropColumn(['pdf_path', 'audio_url']);
        });
    }
};
