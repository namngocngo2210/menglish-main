<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ưu đãi khi thu học phí (03/10/2026): ưu tiên dùng lại ưu đãi trong danh mục / ưu đãi mặc định,
 * chỉ tạo mới cho ca đặc biệt.
 * - promotions.is_default: ưu đãi tự chọn sẵn khi chốt khách đúng cơ sở / khóa.
 * - promotions.is_special + reason + created_by: ưu đãi riêng tạo cho một khách (dùng 1 lần, không hiện trong danh sách chọn).
 * - tuition_receipts.promotion_id + discount_reason: phiếu thu ghi rõ giảm trừ lấy từ ưu đãi nào, hoặc lý do nếu nhập tay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_active');
            $table->boolean('is_special')->default(false)->after('is_default');
            $table->text('reason')->nullable()->after('description');
            $table->foreignId('created_by')->nullable()->after('used_count')->constrained('users')->nullOnDelete();
        });

        Schema::table('tuition_receipts', function (Blueprint $table) {
            $table->foreignId('promotion_id')->nullable()->after('discount_amount')->constrained('promotions')->nullOnDelete();
            $table->string('discount_reason', 500)->nullable()->after('promotion_id');
        });
    }

    public function down(): void
    {
        Schema::table('tuition_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promotion_id');
            $table->dropColumn('discount_reason');
        });

        Schema::table('promotions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['is_default', 'is_special', 'reason']);
        });
    }
};
