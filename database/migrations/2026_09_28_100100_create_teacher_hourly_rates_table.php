<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Đơn giá giờ dạy riêng từng giáo viên, có ngày hiệu lực và lịch sử.
     * Đổi đơn giá = thêm dòng mới với effective_from mới (không sửa dòng cũ),
     * nên ca dạy trong quá khứ luôn tính theo đơn giá hiệu lực tại ngày dạy.
     */
    public function up(): void
    {
        Schema::create('teacher_hourly_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('hourly_rate', 15, 2);
            $table->date('effective_from');
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_hourly_rates');
    }
};
