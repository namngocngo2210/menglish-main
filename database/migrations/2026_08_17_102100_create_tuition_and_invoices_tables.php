<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Hồ sơ học phí học viên (Tuition Ledger)
        Schema::create('student_tuitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->decimal('total_amount', 15, 2)->default(0); // Học phí niêm yết
            $table->decimal('discount_amount', 15, 2)->default(0); // Giảm giá / Ưu đãi
            $table->decimal('final_amount', 15, 2)->default(0); // Thực thu cần nộp
            $table->decimal('paid_amount', 15, 2)->default(0); // Đã nộp
            $table->decimal('debt_amount', 15, 2)->default(0); // Còn nợ
            $table->date('due_date')->nullable(); // Hạn nộp
            $table->string('status', 50)->default('unpaid'); // paid, partial, unpaid, overdue
            $table->string('debt_status', 50)->nullable(); // T-3, T0, T+3
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 2. Phiếu thu học phí (Receipt Vouchers)
        Schema::create('tuition_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number')->unique(); // PT-2026-0889
            $table->foreignId('student_tuition_id')->constrained('student_tuitions')->cascadeOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('payment_method', 50)->default('transfer'); // transfer, cash, pos
            $table->string('transaction_code')->nullable(); // FT2608149882
            $table->date('payment_date')->nullable();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 50)->default('pending'); // pending, approved, rejected
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 3. Yêu cầu Hủy Hóa Đơn / Biên lai
        Schema::create('invoice_cancellations', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number'); // HD-2026-0042
            $table->foreignId('tuition_receipt_id')->nullable()->constrained('tuition_receipts')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->text('reason');
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 50)->default('pending'); // pending, approved, rejected
            $table->timestamps();
        });

        // 4. Nghiệp vụ Hoàn tiền / Khất nợ / Chuyển nhượng
        Schema::create('tuition_refund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('type', 50)->default('refund'); // refund, extension, transfer
            $table->decimal('total_paid', 15, 2)->default(0);
            $table->integer('attended_lessons')->default(0);
            $table->decimal('admin_fee', 15, 2)->default(0);
            $table->decimal('refund_amount', 15, 2)->default(0);
            $table->foreignId('target_student_id')->nullable()->constrained('students')->nullOnDelete(); // Nếu chuyển nhượng
            $table->text('reason')->nullable();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 50)->default('pending'); // pending, approved, rejected
            $table->timestamps();
        });

        // 5. Cấu hình dải số hóa đơn
        Schema::create('invoice_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('template_code')->default('1/001'); // Mẫu số
            $table->string('series_code')->default('C26MEN'); // Ký hiệu
            $table->integer('start_number')->default(1);
            $table->integer('current_number')->default(890);
            $table->string('provider', 50)->default('VNPT Invoice');
            $table->boolean('auto_issue')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_configurations');
        Schema::dropIfExists('tuition_refund_requests');
        Schema::dropIfExists('invoice_cancellations');
        Schema::dropIfExists('tuition_receipts');
        Schema::dropIfExists('student_tuitions');
    }
};
