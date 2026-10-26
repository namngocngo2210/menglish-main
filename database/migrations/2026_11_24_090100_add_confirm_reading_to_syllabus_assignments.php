<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bắt GV đọc trước khi XÁC NHẬN chặng (docs/kho-syllabus.md §7.3 bước 2): lúc GV mở trang "Đọc & xác nhận" lần đầu
 * (1 dòng / người / chặng giao) và bằng chứng đọc lưu khi xác nhận (thời gian đọc tổng quan, tài liệu chặng đã mở).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('syllabus_assignment_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('syllabus_assignment_id')->constrained('syllabus_assignments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('opened_at');
            $table->timestamps();
            $table->unique(['syllabus_assignment_id', 'user_id']);
        });

        Schema::table('syllabus_assignments', function (Blueprint $table) {
            $table->json('confirm_evidence')->nullable()->after('confirmed_by');
        });
    }

    public function down(): void
    {
        Schema::table('syllabus_assignments', function (Blueprint $table) {
            $table->dropColumn('confirm_evidence');
        });
        Schema::dropIfExists('syllabus_assignment_reads');
    }
};
