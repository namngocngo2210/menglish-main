<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * submitted_at = lúc gửi duyệt gần nhất (kể cả gửi lại sau từ chối); màn Duyệt phiếu thu hiển thị & sắp xếp theo cột này.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tuition_receipts', function (Blueprint $table) {
            $table->dateTime('submitted_at')->nullable()->after('status');
        });

        // Best-effort backfill: phiếu đang chờ duyệt lấy updated_at (giờ sửa/gửi lại gần nhất);
        // phiếu đã duyệt lấy created_at. Nháp/từ chối để NULL (sẽ có giờ khi gửi duyệt).
        DB::table('tuition_receipts')->where('status', 'pending')->update(['submitted_at' => DB::raw('updated_at')]);
        DB::table('tuition_receipts')->whereNull('submitted_at')->whereNotIn('status', ['draft', 'rejected'])
            ->update(['submitted_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('tuition_receipts', function (Blueprint $table) {
            $table->dropColumn('submitted_at');
        });
    }
};
