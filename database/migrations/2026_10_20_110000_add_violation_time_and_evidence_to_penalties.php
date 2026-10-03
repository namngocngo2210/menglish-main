<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chủ dự án chốt: vi phạm phải được ghi nhận trong vòng 24h kể từ lúc xảy ra, kèm bằng chứng.
 * - violation_at: thời điểm vi phạm (ngày + giờ); violation_date giữ nguyên để lọc / tính lương theo ngày.
 * - evidence_path: file bằng chứng (ảnh / PDF) lưu trên disk riêng tư.
 * Biên bản cũ: violation_at lấy từ violation_date (00:00), không có bằng chứng.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->dateTime('violation_at')->nullable()->after('violation_date');
            $table->string('evidence_path')->nullable()->after('notes');
        });

        DB::table('penalties')->whereNull('violation_at')->whereNotNull('violation_date')->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('penalties')->where('id', $row->id)->update([
                        'violation_at' => Carbon::parse($row->violation_date)->startOfDay()->toDateTimeString(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->dropColumn(['violation_at', 'evidence_path']);
        });
    }
};
