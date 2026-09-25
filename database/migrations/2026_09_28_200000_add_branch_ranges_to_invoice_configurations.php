<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 4 — Dải số hóa đơn theo chi nhánh & không cấp lại số đã dùng.
     * - invoice_configurations: thêm branch_id (NULL = dải mặc định dùng chung khi chi nhánh chưa có dải riêng),
     *   end_number (NULL = không giới hạn) và is_active. Dải hiện có giữ nguyên, trở thành dải mặc định.
     * - tuition_receipts.invoice_number: UNIQUE. Số trùng từ dữ liệu cũ được giữ ở phiếu đầu tiên,
     *   các phiếu sau gắn hậu tố "-DUP{id}" (ghi chú lại) để không mất dấu vết và không còn trùng.
     */
    public function up(): void
    {
        Schema::table('invoice_configurations', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_configurations', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete();
            }
            if (! Schema::hasColumn('invoice_configurations', 'end_number')) {
                $table->unsignedBigInteger('end_number')->nullable()->after('start_number');
            }
            if (! Schema::hasColumn('invoice_configurations', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('auto_issue');
            }
        });

        $duplicates = DB::table('tuition_receipts')
            ->whereNotNull('invoice_number')
            ->select('invoice_number')
            ->groupBy('invoice_number')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('invoice_number');

        foreach ($duplicates as $invoiceNumber) {
            $rows = DB::table('tuition_receipts')
                ->where('invoice_number', $invoiceNumber)
                ->orderBy('id')
                ->get(['id', 'notes']);

            foreach ($rows->skip(1) as $row) {
                DB::table('tuition_receipts')->where('id', $row->id)->update([
                    'invoice_number' => $invoiceNumber.'-DUP'.$row->id,
                    'notes' => trim(($row->notes ? $row->notes."\n" : '')
                        .'[Phase 4] Số HĐ '.$invoiceNumber.' bị cấp trùng trước khi có ràng buộc duy nhất; đổi thành '
                        .$invoiceNumber.'-DUP'.$row->id.' để đối soát.'),
                ]);
            }
        }

        Schema::table('tuition_receipts', function (Blueprint $table) {
            $table->unique('invoice_number', 'tuition_receipts_invoice_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tuition_receipts', function (Blueprint $table) {
            $table->dropUnique('tuition_receipts_invoice_number_unique');
        });

        Schema::table('invoice_configurations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['end_number', 'is_active']);
        });
    }
};
