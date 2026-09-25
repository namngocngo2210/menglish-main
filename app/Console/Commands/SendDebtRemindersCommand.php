<?php

namespace App\Console\Commands;

use App\Models\StudentTuition;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Gửi nhắc nợ học phí tự động theo mốc cấu hình (T-3 / T0 / T+3).
 * Chỉ xử lý đúng ngày chạm mốc, idempotent trong ngày nhờ record_code
 * DEBTREMIND-{mốc}-{tuition_id}-{ngày} trong notifyDebtReminderByMilestone.
 */
class SendDebtRemindersCommand extends Command
{
    protected $signature = 'tuition:send-debt-reminders {--dry-run}';

    protected $description = 'Gửi nhắc học phí theo mốc cấu hình DebtReminderRule (T-3, T0, T+3)';

    public function handle(NotificationService $service): int
    {
        $today = now()->startOfDay();

        $tuitions = StudentTuition::with(['student', 'classModel'])
            ->whereNotNull('due_date')
            ->where('debt_amount', '>', 0)
            ->where(function ($query) {
                // Chỉ lấy hợp đồng có ngày chạm đúng một trong 3 mốc hôm nay
                $query->whereDate('due_date', $today->copy()->addDays(3)->toDateString())   // T-3
                    ->orWhereDate('due_date', $today->toDateString())                        // T0
                    ->orWhereDate('due_date', $today->copy()->subDays(3)->toDateString());   // T+3
            })
            ->get();

        $sent = 0;
        $skipped = 0;
        foreach ($tuitions as $tuition) {
            $result = $service->notifyDebtReminderByMilestone($tuition);
            if ($result['sent']) {
                $sent++;
                $this->info("[{$result['milestone']}] {$tuition->student?->name} — ".number_format((float) $tuition->debt_amount).'đ');
            } else {
                $skipped++;
                $this->line("Bỏ qua #{$tuition->id}: {$result['reason']}");
            }
        }

        $this->info($this->option('dry-run') ? 'Dry-run: ' : 'Hoàn tất: ')."{$sent} đã gửi, {$skipped} bỏ qua.";

        return self::SUCCESS;
    }
}
