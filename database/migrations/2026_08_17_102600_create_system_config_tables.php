<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tài khoản ngân hàng thu tiền & VietQR
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('bank_code', 20)->default('VCB'); // VCB, TCB, MBB, ACB...
            $table->string('bank_name'); // Vietcombank
            $table->string('account_number'); // 9988 2345 6789
            $table->string('account_holder'); // CONG TY CP GIAO DUC MENGLISH
            $table->string('branch_location')->nullable(); // Chi nhánh Cầu Giấy
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->boolean('is_default_vietqr')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. Quy tắc & Mẫu tin nhắn nhắc nợ tự động
        Schema::create('debt_reminder_rules', function (Blueprint $table) {
            $table->id();
            $table->string('milestone_key')->unique(); // T-3, T0, T+3
            $table->string('title'); // Nhắc trước hạn 3 ngày
            $table->text('template_content');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_reminder_rules');
        Schema::dropIfExists('bank_accounts');
    }
};
