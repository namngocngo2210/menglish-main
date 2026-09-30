<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SLA học phí (chủ dự án 30/09/2026): thông báo 1 tuần trước hạn, nhắc lại 1–3 ngày trước hạn. Hệ thống đã cài chỉ có
 * T-3 / T0 / T+3 → thêm mốc T-7 (bật) nếu chưa có mốc nào 7 ngày trước hạn. Chưa có mốc nào = lệnh dùng mặc định (đã gồm T-7).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('debt_reminder_rules')->exists()) {
            return;
        }
        $hasWeek = DB::table('debt_reminder_rules')->get(['milestone_key', 'offset_days'])
            ->contains(fn ($rule) => (int) ($rule->offset_days ?? (preg_match('/^T([+-]?\d+)$/i', (string) $rule->milestone_key, $m) ? $m[1] : 0)) === -7);
        if ($hasWeek || DB::table('debt_reminder_rules')->where('milestone_key', 'T-7')->exists()) {
            return;
        }

        DB::table('debt_reminder_rules')->insert([
            'milestone_key' => 'T-7',
            'offset_days' => -7,
            'title' => 'Thông báo trước hạn 1 tuần (T-7)',
            'template_content' => 'Chào phụ huynh học viên {ten_hoc_vien}, học phí lớp {lop_hoc} ({so_tien}) sẽ đến hạn ngày {han_dong} (sau 1 tuần). Vui lòng sắp xếp thanh toán đúng hạn. Xin cảm ơn!',
            'channels' => json_encode(['portal', 'email']),
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('debt_reminder_rules')->where('milestone_key', 'T-7')->where('title', 'Thông báo trước hạn 1 tuần (T-7)')->delete();
    }
};
