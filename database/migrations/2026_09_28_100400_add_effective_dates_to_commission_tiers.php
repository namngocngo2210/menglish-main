<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mốc hoa hồng có ngày hiệu lực và lịch sử: sửa một bậc = tạo phiên bản mới
     * (effective_from mới) và đóng phiên bản cũ (effective_to), không ghi đè.
     * Bậc cũ chưa có effective_from được hiểu là hiệu lực từ đầu.
     */
    public function up(): void
    {
        Schema::table('commission_tiers', function (Blueprint $table) {
            $table->date('effective_from')->nullable()->after('bonus_amount');
            $table->date('effective_to')->nullable()->after('effective_from');
            $table->foreignId('replaces_id')->nullable()->after('effective_to')->constrained('commission_tiers')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->after('replaces_id')->constrained('users')->nullOnDelete();
            $table->index(['effective_from', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::table('commission_tiers', function (Blueprint $table) {
            $table->dropIndex(['effective_from', 'effective_to']);
            $table->dropConstrainedForeignId('replaces_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['effective_from', 'effective_to']);
        });
    }
};
