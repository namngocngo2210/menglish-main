<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rà soát báo cáo 16/10/2026:
 * - `review_note`: nhận xét của Admin khi "Yêu cầu bổ sung" (báo cáo về trạng thái `returned` cho người viết sửa).
 * - Mỗi người chỉ một báo cáo / loại / kỳ: hai tab cùng bấm Lưu từng tạo hai bản. Bản trùng cũ gộp về bản sửa gần nhất.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_reports', function (Blueprint $table) {
            $table->text('review_note')->nullable()->after('reviewed_at');
        });

        $duplicates = DB::table('staff_reports')->whereNotNull('kind')->whereNotNull('period_key')
            ->select('user_id', 'kind', 'period_key')->groupBy('user_id', 'kind', 'period_key')->havingRaw('count(*) > 1')->get();
        foreach ($duplicates as $dup) {
            $ids = DB::table('staff_reports')->where('user_id', $dup->user_id)->where('kind', $dup->kind)->where('period_key', $dup->period_key)
                ->orderByDesc('updated_at')->orderByDesc('id')->pluck('id');
            DB::table('staff_reports')->whereIn('id', $ids->slice(1)->all())->delete();
        }

        Schema::table('staff_reports', function (Blueprint $table) {
            $table->unique(['user_id', 'kind', 'period_key'], 'staff_reports_user_kind_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('staff_reports', function (Blueprint $table) {
            $table->dropUnique('staff_reports_user_kind_period_unique');
            $table->dropColumn('review_note');
        });
    }
};
