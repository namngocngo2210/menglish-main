<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penalties', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // BB-2026-014
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // Nhân sự vi phạm
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->string('violation_type'); // Đến muộn > 15 phút, Chậm nộp nhận xét, Nghỉ không phép
            $table->date('violation_date');
            $table->decimal('amount', 15, 2)->default(0); // Số tiền phạt
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 50)->default('pending'); // pending, confirmed, deducted, cancelled
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penalties');
    }
};
