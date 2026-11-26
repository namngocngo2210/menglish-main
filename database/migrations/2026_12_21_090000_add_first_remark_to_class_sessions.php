<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TK-2026-0173 (US R-06 / AC-S08): ghi nhận GV nào và lúc nào lưu nhận xét học sinh đầu tiên của buổi, làm tín hiệu
 * đối soát chấm công/lương. Không backfill: academic_records chỉ giữ người lưu SAU CÙNG và updated_at bị ghi đè khi sửa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->foreignId('nhan_xet_boi')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('nhan_xet_gui_luc')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('nhan_xet_boi');
            $table->dropColumn('nhan_xet_gui_luc');
        });
    }
};
