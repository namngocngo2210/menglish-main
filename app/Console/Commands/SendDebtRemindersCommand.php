<?php

namespace App\Console\Commands;

use App\Models\DebtReminderRule;
use App\Models\StudentTuition;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Gửi nhắc nợ học phí tự động theo mốc cấu hình (DebtReminderRule.offset_days: số ngày so với hạn đóng,
 * âm = trước hạn). Chưa cấu hình mốc nào thì dùng mặc định T-3 / T0 / T+3.
 * - Chỉ xử lý đúng ngày chạm mốc, idempotent trong ngày nhờ record_code DEBTREMIND-{mốc}-{tuition_id}-{ngày}.
 * - Bỏ qua khoản đang khất nợ / bảo lưu (reminder_paused_until > hôm nay).
 */
class SendDebtRemindersCommand extends Command
{
    protected $signature = 'tuition:send-debt-reminders {--dry-run}';

    protected $description = 'Gửi nhắc học phí theo mốc cấu hình DebtReminderRule (mặc định T-3, T0, T+3)';

    /** Mốc mặc định khi chưa cấu hình rule nào. */
    private const DEFAULT_MILESTONES = ['T-3' => -3, 'T0' => 0, 'T+3' => 3];

    public function handle(NotificationService $service): int
    {
        $today = now()->startOfDay();
        $dryRun = (bool) $this->option('dry-run');
        $sent = 0;
        $skipped = 0;

        foreach ($this->milestones() as $milestone => $offset) {
            // Mốc offset ngày: hạn đóng = hôm nay - offset (vd. T-3 -> hạn đóng sau 3 ngày).
            $dueDate = $today->copy()->subDays($offset)->toDateString();

            $tuitions = StudentTuition::with(['student', 'classModel'])
                ->whereDate('due_date', $dueDate)
                ->where('debt_amount', '>', 0)
                ->remindable($today->toDateString())
                ->get();

            foreach ($tuitions as $tuition) {
                if ($dryRun) {
                    // Dry-run: chỉ liệt kê, không ghi thông báo / không gửi email.
                    $sent++;
                    $this->info("[dry-run][{$milestone}] {$tuition->student?->name} — ".number_format((float) $tuition->debt_amount).'đ');

                    continue;
                }

                $result = $service->notifyDebtReminderByMilestone($tuition, $milestone);
                if ($result['sent']) {
                    $sent++;
                    $this->info("[{$result['milestone']}] {$tuition->student?->name} — ".number_format((float) $tuition->debt_amount).'đ');
                } else {
                    $skipped++;
                    $this->line("Bỏ qua #{$tuition->id}: {$result['reason']}");
                }
            }
        }

        $this->info(($dryRun ? 'Dry-run: ' : 'Hoàn tất: ')."{$sent} đã gửi, {$skipped} bỏ qua.");

        return self::SUCCESS;
    }

    /**
     * @return array<string, int> mã mốc => số ngày so với hạn đóng
     */
    private function milestones(): array
    {
        if (! DebtReminderRule::query()->exists()) {
            return self::DEFAULT_MILESTONES;
        }

        return DebtReminderRule::query()
            ->where('is_enabled', true)
            ->get()
            ->mapWithKeys(fn (DebtReminderRule $rule) => [$rule->milestone_key => $rule->effectiveOffset()])
            ->filter(fn ($offset) => $offset !== null)
            ->all();
    }
}
