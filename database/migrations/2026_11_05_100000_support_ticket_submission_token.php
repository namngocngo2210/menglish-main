<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mã lần gửi của form tạo ticket (sinh khi mở form, giữ nguyên khi gửi lại): cùng người tạo + cùng mã chỉ ra một
     * ticket, nên request gửi lặp (mạng chập chờn, lỗi gateway rồi bấm lại) không tạo ticket trùng.
     */
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('submission_token', 64)->nullable()->after('code');
            $table->unique(['creator_id', 'submission_token']);
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropUnique(['creator_id', 'submission_token']);
            $table->dropColumn('submission_token');
        });
    }
};
