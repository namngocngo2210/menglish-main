<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hoa hồng (bản sửa A6): bậc chọn theo SỐ HS CHỐT trong kỳ (không còn theo doanh thu).
     * Bậc cũ theo doanh thu (min_students NULL) giữ lại làm lịch sử, không còn được dùng.
     * Chưa có bậc theo số HS → tạo 3 bậc mặc định 3% / 4% / 5% (config/payroll.php), hiệu lực từ đầu.
     */
    public function up(): void
    {
        Schema::table('commission_tiers', function (Blueprint $table) {
            $table->unsignedInteger('min_students')->nullable()->after('max_revenue');
            $table->unsignedInteger('max_students')->nullable()->after('min_students');
        });

        if (! DB::table('commission_tiers')->whereNotNull('min_students')->exists()) {
            foreach (config('payroll.commission.default_tiers', []) as $tier) {
                DB::table('commission_tiers')->insert($tier + [
                    'min_revenue' => 0,
                    'max_revenue' => null,
                    'renew_percent' => 0,
                    'bonus_amount' => 0,
                    'effective_from' => null,
                    'effective_to' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('commission_tiers')->whereNotNull('min_students')->whereNull('replaces_id')->whereNull('created_by')->delete();
        Schema::table('commission_tiers', function (Blueprint $table) {
            $table->dropColumn(['min_students', 'max_students']);
        });
    }
};
