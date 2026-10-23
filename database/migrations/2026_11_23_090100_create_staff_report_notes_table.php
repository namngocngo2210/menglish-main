<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ghi chú trên báo cáo tuần Học thuật (Sheet 4 câu 9): Quản lý và chính người làm báo cáo xem và ghi chú. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_report_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_report_id')->constrained('staff_reports')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_report_notes');
    }
};
