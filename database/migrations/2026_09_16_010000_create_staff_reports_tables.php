<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Báo cáo & Nhật ký theo vai trò:
        //  - journal  : Nhật ký học vụ (sự vụ nổi cộm, mỗi người tự note)
        //  - daily    : Báo cáo ngày (học vụ)
        //  - weekly   : Báo cáo tuần (học thuật)
        //  - monthly  : Báo cáo tháng / tổng kết (giáo viên)
        Schema::create('staff_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 20); // journal, daily, weekly, monthly
            $table->string('title');
            $table->longText('content')->nullable();
            $table->string('severity', 20)->nullable(); // normal, important, urgent (cho journal)
            $table->date('report_date');
            $table->string('status', 20)->default('submitted'); // open, following, resolved (journal) | submitted (report)
            $table->timestamps();

            $table->index(['type', 'report_date']);
            $table->index(['user_id', 'type']);
        });

        // Các lượt follow-up / xử lý cho một mục nhật ký hoặc báo cáo
        Schema::create('staff_report_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_report_id')->constrained('staff_reports')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_report_followups');
        Schema::dropIfExists('staff_reports');
    }
};
