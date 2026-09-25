<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 4 — chống ghi nhận chuyển khoản 2 lần.
     * transfer_reference = mã giao dịch ngân hàng đã chuẩn hoá, chỉ có giá trị khi phiếu chuyển khoản/VietQR
     * đang chờ duyệt hoặc đã duyệt (model TuitionReceipt tự tính khi lưu). UNIQUE (NULL được lặp) nên
     * một mã giao dịch không thể nằm trên 2 phiếu còn hiệu lực. Dữ liệu cũ bị trùng: giữ ở phiếu đã duyệt / cũ nhất.
     */
    public function up(): void
    {
        Schema::table('tuition_receipts', function (Blueprint $table) {
            $table->string('transfer_reference', 150)->nullable()->after('transaction_code');
        });

        $taken = [];
        DB::table('tuition_receipts')
            ->whereIn('payment_method', ['transfer', 'vietqr'])
            ->whereIn('status', ['pending', 'approved'])
            ->whereNotNull('transaction_code')
            ->orderByRaw("CASE WHEN status = 'approved' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get(['id', 'transaction_code'])
            ->each(function ($row) use (&$taken): void {
                $reference = strtoupper(preg_replace('/\s+/', '', (string) $row->transaction_code));
                if ($reference === '' || isset($taken[$reference])) {
                    return;
                }
                $taken[$reference] = true;
                DB::table('tuition_receipts')->where('id', $row->id)->update(['transfer_reference' => $reference]);
            });

        Schema::table('tuition_receipts', function (Blueprint $table) {
            $table->unique('transfer_reference', 'tuition_receipts_transfer_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tuition_receipts', function (Blueprint $table) {
            $table->dropUnique('tuition_receipts_transfer_reference_unique');
            $table->dropColumn('transfer_reference');
        });
    }
};
