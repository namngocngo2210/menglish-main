<?php

use App\Models\CrmCustomer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Khách chưa có phone_normalized (tạo ngoài form CRM) không tìm được theo SĐT viết liền
 * và không bị chặn trùng. Điền lại; bỏ qua khách mà SĐT chuẩn hoá đã thuộc khách khác.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('crm_customers')
            ->whereNull('phone_normalized')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'phone'])
            ->each(function (object $row): void {
                $normalized = CrmCustomer::normalizePhone($row->phone);
                if ($normalized === '' || DB::table('crm_customers')->where('phone_normalized', $normalized)->exists()) {
                    return;
                }
                DB::table('crm_customers')->where('id', $row->id)->update(['phone_normalized' => $normalized]);
            });
    }

    public function down(): void
    {
        // Không hoàn tác: dữ liệu chuẩn hoá không làm mất thông tin.
    }
};
