<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 4 — nhật ký đôn đốc công nợ quá hạn: "Đã liên hệ" (ghi chú + thời gian) và "Báo cáo Admin".
     */
    public function up(): void
    {
        Schema::create('tuition_contact_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_tuition_id')->constrained('student_tuitions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30); // contacted | reported_admin
            $table->text('note')->nullable();
            $table->dateTime('contacted_at');
            $table->timestamps();

            $table->index(['student_tuition_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tuition_contact_logs');
    }
};
