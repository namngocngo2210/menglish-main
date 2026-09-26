<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bước cuối "Đã khắc phục" của mockup Danh sách vi phạm (epic-8-danh-sach-phat): sau khi nộp phạt / trừ lương,
     * Học vụ / Quản lý "Ghi nhận khắc phục". Lưu mốc riêng, không đổi trạng thái tiền (không ảnh hưởng bảng lương).
     */
    public function up(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->timestamp('remedied_at')->nullable()->after('paid_at');
            $table->foreignId('remedied_by')->nullable()->after('remedied_at')->constrained('users')->nullOnDelete();
            $table->string('remedy_note', 1000)->nullable()->after('remedied_by');
        });
    }

    public function down(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('remedied_by');
            $table->dropColumn(['remedied_at', 'remedy_note']);
        });
    }
};
