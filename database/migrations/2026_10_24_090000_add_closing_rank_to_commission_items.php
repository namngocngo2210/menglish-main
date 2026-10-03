<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hoa hồng tăng tiến theo mốc: mỗi khoản ghi học viên là HS thứ mấy trong tháng chốt của người phụ trách,
     * để phiếu lương / trang cá nhân giải thích được vì sao khoản đó mang % của mốc nào.
     */
    public function up(): void
    {
        Schema::table('commission_items', function (Blueprint $table) {
            $table->unsignedInteger('closing_rank')->nullable()->after('closed_count');
        });
    }

    public function down(): void
    {
        Schema::table('commission_items', function (Blueprint $table) {
            $table->dropColumn('closing_rank');
        });
    }
};
