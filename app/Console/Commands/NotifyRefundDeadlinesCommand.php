<?php

namespace App\Console\Commands;

use App\Services\Tuition\TuitionSlaService;
use Illuminate\Console\Command;

/**
 * Hằng ngày: hồ sơ hoàn phí / chuyển nhượng còn chờ duyệt, còn ≤ 1 ngày tới hạn (1 tuần, cùng tháng) hoặc đã quá hạn
 * → nhắc người có quyền duyệt loại hồ sơ đó. Idempotent theo hồ sơ / loại nhắc / ngày.
 */
class NotifyRefundDeadlinesCommand extends Command
{
    protected $signature = 'tuition:notify-refund-deadlines';

    protected $description = 'Nhắc hồ sơ hoàn phí / chuyển nhượng sắp hoặc đã quá hạn xử lý 1 tuần';

    public function handle(TuitionSlaService $sla): int
    {
        $sent = $sla->notifyRefundDeadlines(now());
        $this->info("Đã gửi {$sent} thông báo hạn xử lý hoàn phí / chuyển nhượng.");

        return self::SUCCESS;
    }
}
