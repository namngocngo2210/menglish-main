<?php

namespace App\Jobs;

use App\Models\PayrollPeriod;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Đồng bộ & tính lại bảng lương một kỳ chạy nền (cho mọi nhân sự, từ chấm công, KPI, phạt, hoa hồng). */
class CalculatePayrollPeriod extends BackgroundTaskJob
{
    protected function process(): array
    {
        $period = PayrollPeriod::find($this->task->payload['period_id'] ?? null);
        if (! $period) {
            return ['ok' => false, 'message' => 'Kỳ lương không còn tồn tại.'];
        }

        try {
            $period->calculatePayrollForPeriod();
        } catch (LockTimeoutException) {
            return ['ok' => false, 'message' => 'Kỳ lương đang được tính bởi phiên khác — vui lòng thử lại sau ít phút.'];
        } catch (HttpException $e) {
            // Kỳ đã chốt / gửi GV xem / trả trong lúc chờ chạy.
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return ['ok' => true, 'message' => "Đã đồng bộ và tính toán lại bảng lương {$period->title} từ Chấm công, KPI và Phạt."];
    }

    protected function link(): string
    {
        return route('payroll.periods.show', $this->task->payload['period_id']);
    }
}
