<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sổ hoa hồng tuyển sinh: mỗi phiếu thu tính hoa hồng là một khoản, ghi % bậc của kỳ phát sinh
     * (kỳ phiếu được duyệt). Khoản chỉ được TRẢ ở kỳ lương mà gate kép đạt (đủ 30 ngày từ ngày chốt và
     * đủ 3/3 mốc chăm sóc); chưa đạt thì HOÃN sang kỳ sau (không mất). Kỳ trả được duyệt → settled_at.
     */
    public function up(): void
    {
        Schema::create('commission_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tuition_receipt_id')->unique()->constrained('tuition_receipts')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('crm_customer_id')->nullable()->constrained('crm_customers')->nullOnDelete();
            $table->decimal('base_amount', 15, 2);
            $table->decimal('percent', 5, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedInteger('closed_count')->default(0);
            $table->date('earned_period_start');
            $table->date('earned_period_end');
            $table->timestamp('closed_at')->nullable();
            $table->string('status', 20)->default('deferred'); // deferred | payable | paid | void
            $table->string('deferred_reason', 255)->nullable();
            $table->foreignId('payroll_record_id')->nullable()->constrained('payroll_records')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'settled_at']);
            $table->index(['earned_period_start', 'earned_period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_items');
    }
};
