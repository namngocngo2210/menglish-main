<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hoa hồng tuyển sinh (CRM) trước đây được tính vào thực lĩnh nhưng không có
     * cột lưu — bị Eloquent bỏ qua âm thầm và bị xoá khi tính lại net.
     */
    public function up(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->decimal('commission_bonus', 15, 2)->default(0)->after('renew_bonus');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->dropColumn('commission_bonus');
        });
    }
};
