<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - payroll_periods.calculated_at: mốc lần tính gần nhất, để chặn duyệt khi
     *   chấm công/phạt thay đổi sau lần tính.
     * - penalties.payroll_record_id: biên bản nào đã thực sự được trừ vào bản ghi
     *   lương nào, để khi duyệt chỉ đóng dấu "deducted" đúng các biên bản đó.
     */
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->timestamp('calculated_at')->nullable()->after('total_amount');
            $table->index(['status', 'start_date', 'end_date']);
        });

        Schema::table('penalties', function (Blueprint $table) {
            $table->foreignId('payroll_record_id')->nullable()->after('status')
                ->constrained('payroll_records')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payroll_record_id');
        });

        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropIndex(['status', 'start_date', 'end_date']);
            $table->dropColumn('calculated_at');
        });
    }
};
