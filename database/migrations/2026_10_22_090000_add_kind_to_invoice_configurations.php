<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loại dải số hóa đơn: "electronic" (HĐĐT, cấp số khi duyệt phiếu — như trước) hoặc "paper" (hóa đơn giấy thu
 * tiền mặt: hệ thống cấp số theo thứ tự của chi nhánh ngay khi lập phiếu, Học vụ ghi đúng số đó lên hóa đơn giấy).
 * Dải đang có giữ nguyên là HĐĐT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_configurations', function (Blueprint $table) {
            $table->string('kind', 20)->default('electronic')->after('branch_id');
            $table->index(['kind', 'branch_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_configurations', function (Blueprint $table) {
            $table->dropIndex(['kind', 'branch_id', 'is_active']);
            $table->dropColumn('kind');
        });
    }
};
