<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 4 — Bảo lưu & khất nợ có tác dụng thật, nhắc nợ theo lịch cấu hình.
     * - tuition_refund_requests: hạn mới khi khất nợ; từ/đến ngày khi bảo lưu.
     * - student_tuitions: tạm dừng nhắc nợ đến ngày; thời gian bảo lưu và số buổi / công nợ được đóng băng.
     * - debt_reminder_rules: mốc nhắc theo số ngày so với hạn đóng (âm = trước hạn) và kênh gửi.
     */
    public function up(): void
    {
        Schema::table('tuition_refund_requests', function (Blueprint $table) {
            $table->date('extended_due_date')->nullable()->after('refund_amount');
            $table->date('defer_from')->nullable()->after('extended_due_date');
            $table->date('defer_to')->nullable()->after('defer_from');
        });

        Schema::table('student_tuitions', function (Blueprint $table) {
            $table->date('reminder_paused_until')->nullable()->after('due_date');
            $table->date('deferred_from')->nullable()->after('reminder_paused_until');
            $table->date('deferred_until')->nullable()->after('deferred_from');
            $table->unsignedInteger('frozen_remaining_sessions')->nullable()->after('deferred_until');
            $table->decimal('frozen_debt_amount', 15, 2)->nullable()->after('frozen_remaining_sessions');
        });

        Schema::table('debt_reminder_rules', function (Blueprint $table) {
            $table->integer('offset_days')->nullable()->after('milestone_key');
            $table->json('channels')->nullable()->after('template_content');
        });

        // Mốc cũ T-3 / T0 / T+3 (và dạng d_plus_7, d_minus_5) -> số ngày so với hạn đóng.
        DB::table('debt_reminder_rules')->orderBy('id')->get(['id', 'milestone_key'])->each(function ($rule): void {
            $key = strtoupper((string) $rule->milestone_key);
            $offset = null;
            if (preg_match('/^T([+-]?\d+)$/', $key, $m)) {
                $offset = (int) $m[1];
            } elseif (preg_match('/^D_(PLUS|MINUS)_(\d+)$/', $key, $m)) {
                $offset = ($m[1] === 'PLUS' ? 1 : -1) * (int) $m[2];
            }

            DB::table('debt_reminder_rules')->where('id', $rule->id)->update([
                'offset_days' => $offset,
                'channels' => json_encode(['portal', 'email']),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('debt_reminder_rules', function (Blueprint $table) {
            $table->dropColumn(['offset_days', 'channels']);
        });

        Schema::table('student_tuitions', function (Blueprint $table) {
            $table->dropColumn(['reminder_paused_until', 'deferred_from', 'deferred_until', 'frozen_remaining_sessions', 'frozen_debt_amount']);
        });

        Schema::table('tuition_refund_requests', function (Blueprint $table) {
            $table->dropColumn(['extended_due_date', 'defer_from', 'defer_to']);
        });
    }
};
