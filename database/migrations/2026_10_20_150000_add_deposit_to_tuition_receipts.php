<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SLA nộp tiền về tài khoản công ty: tiền thu trong ngày phải nộp trước 19:00 cùng ngày.
 * deposited_at = lúc kế toán xác nhận đã nộp; deposited_by = người xác nhận.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tuition_receipts', function (Blueprint $table) {
            $table->dateTime('deposited_at')->nullable()->after('approved_at');
            $table->foreignId('deposited_by')->nullable()->after('deposited_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tuition_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deposited_by');
            $table->dropColumn('deposited_at');
        });
    }
};
