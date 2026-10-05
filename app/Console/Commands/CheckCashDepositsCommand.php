<?php

namespace App\Console\Commands;

use App\Services\Tuition\TuitionSlaService;
use Illuminate\Console\Command;

/**
 * Mỗi 15 phút: phiếu tiền mặt thu hôm nay quá giờ chốt (SLA tuition.cash_deposit, mặc định 19:00) chưa được xác nhận nộp về TK công ty → nhắc người thu
 * và người có quyền xác nhận. Không tự phạt. Idempotent theo phiếu.
 */
class CheckCashDepositsCommand extends Command
{
    protected $signature = 'tuition:check-cash-deposits';

    protected $description = 'Nhắc tiền mặt thu trong ngày chưa nộp về TK công ty trước giờ chốt (Cấu hình SLA)';

    public function handle(TuitionSlaService $sla): int
    {
        $sent = $sla->notifyUndepositedCash(now());
        $this->info("Đã gửi {$sent} thông báo tiền mặt chưa nộp về TK công ty.");

        return self::SUCCESS;
    }
}
