<?php

namespace App\Console\Commands;

use App\Services\AdjustmentSlaService;
use Illuminate\Console\Command;

/**
 * Yêu cầu giãn tiến độ giáo trình chờ duyệt quá 3 ngày → báo người duyệt (Học thuật / Admin) đúng 1 lần mỗi yêu cầu.
 * Idempotent: đánh dấu syllabus_adjustment_requests.sla_notified_at.
 */
class NotifyAdjustmentSlaCommand extends Command
{
    protected $signature = 'syllabus:notify-adjustment-sla';

    protected $description = 'Báo người duyệt các yêu cầu giãn tiến độ quá hạn SLA 3 ngày';

    public function handle(AdjustmentSlaService $sla): int
    {
        $this->info('Đã báo '.$sla->notifyBreaches().' yêu cầu giãn tiến độ quá hạn duyệt.');

        return self::SUCCESS;
    }
}
