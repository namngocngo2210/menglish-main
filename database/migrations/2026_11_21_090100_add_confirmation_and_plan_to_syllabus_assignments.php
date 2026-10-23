<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Giao chặng theo quy trình (docs/kho-syllabus.md §7.1, §7.3): ngày bắt đầu + số buổi dự kiến (ngày kết thúc `deadline` tự
 * tính theo TKB), GV xác nhận nhận syllabus, mốc đã nhắc sau 24h. Backfill: ngày bắt đầu = ngày mở chặng, số buổi = số buổi
 * syllabus của chặng.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('syllabus_assignments', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('stage_name');
            $table->unsignedSmallInteger('planned_sessions')->nullable()->after('start_date');
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirm_reminded_at')->nullable();
        });

        DB::table('syllabus_assignments')->whereNull('start_date')->whereNotNull('opened_at')
            ->update(['start_date' => DB::raw('DATE(opened_at)')]);
        $counts = DB::table('syllabus_lessons')
            ->join('syllabus_units', 'syllabus_units.id', '=', 'syllabus_lessons.unit_id')
            ->whereNull('syllabus_lessons.deleted_at')->whereNotNull('syllabus_units.stage_id')
            ->groupBy('syllabus_units.stage_id')
            ->selectRaw('syllabus_units.stage_id as stage_id, COUNT(*) as total')
            ->pluck('total', 'stage_id');
        foreach ($counts as $stageId => $count) {
            DB::table('syllabus_assignments')->where('stage_id', $stageId)->whereNull('planned_sessions')
                ->update(['planned_sessions' => min(65535, (int) $count)]);
        }
        // Chặng đang chạy trước khi có bước xác nhận: coi như GV đã nhận (không nhắc lại hàng loạt khi triển khai).
        DB::table('syllabus_assignments')->where('status', 'in_progress')->whereNull('confirmed_at')
            ->update(['confirmed_at' => DB::raw('COALESCE(opened_at, created_at)')]);
    }

    public function down(): void
    {
        Schema::table('syllabus_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['start_date', 'planned_sessions', 'confirmed_at', 'confirm_reminded_at']);
        });
    }
};
