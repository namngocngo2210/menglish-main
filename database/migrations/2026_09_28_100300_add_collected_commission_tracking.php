<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hoa hồng tuyển sinh theo A6 (25/09/2026): tính trên tổng tiền THỰC THU
     * (phiếu thu đã duyệt, gồm giáo trình/đồ dùng), chỉ khách mới, vào tháng
     * phiếu được duyệt; hoàn phí thì người duyệt chọn có thu hồi hay không.
     */
    public function up(): void
    {
        // Mốc duyệt phiếu thu = tháng tính hoa hồng. Phiếu cũ đã duyệt lấy updated_at làm mốc gần đúng.
        Schema::table('tuition_receipts', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('approver_id');
            $table->index(['status', 'approved_at']);
        });
        DB::table('tuition_receipts')->where('status', 'approved')->whereNull('approved_at')
            ->update(['approved_at' => DB::raw('updated_at')]);

        // Quyết định thu hồi hoa hồng khi duyệt hoàn phí
        Schema::table('tuition_refund_requests', function (Blueprint $table) {
            $table->boolean('clawback_commission')->nullable()->after('status');
            $table->decimal('clawback_amount', 15, 2)->default(0)->after('clawback_commission');
            $table->foreignId('clawback_user_id')->nullable()->after('clawback_amount')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('clawback_user_id');
        });

        // Điều chỉnh hoa hồng (âm = thu hồi) chờ trừ vào lần tính lương kế tiếp của sale
        Schema::create('commission_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tuition_refund_request_id')->nullable()->constrained('tuition_refund_requests')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('payroll_record_id')->nullable()->constrained('payroll_records')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'settled_at']);
        });

        Schema::table('payroll_records', function (Blueprint $table) {
            // Doanh thu thực thu làm căn cứ hoa hồng của kỳ + khoản thu hồi hoa hồng (khấu trừ)
            $table->decimal('commission_base', 15, 2)->default(0)->after('commission_bonus');
            $table->decimal('commission_clawback', 15, 2)->default(0)->after('commission_base');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->dropColumn(['commission_base', 'commission_clawback']);
        });
        Schema::dropIfExists('commission_adjustments');
        Schema::table('tuition_refund_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('clawback_user_id');
            $table->dropColumn(['clawback_commission', 'clawback_amount', 'approved_at']);
        });
        Schema::table('tuition_receipts', function (Blueprint $table) {
            $table->dropIndex(['status', 'approved_at']);
            $table->dropColumn('approved_at');
        });
    }
};
