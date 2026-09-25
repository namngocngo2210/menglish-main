<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nhận xét học thử (BA Q1 — học thử bổ sung): giáo viên buổi đó nhận xét khách như học sinh chính thức
 * (thực hành ngữ pháp, tinh thần học tập, kết quả, nhận xét chi tiết). Nhận xét lưu theo khách (customer_id),
 * không theo học viên.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_trial_bookings', function (Blueprint $table) {
            $table->json('remarks')->nullable()->after('feedback');
        });
    }

    public function down(): void
    {
        Schema::table('crm_trial_bookings', function (Blueprint $table) {
            $table->dropColumn('remarks');
        });
    }
};
