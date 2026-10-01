<?php

namespace App\Console\Commands;

use App\Services\BigTestSlaService;
use Illuminate\Console\Command;

/**
 * SLA Big Test hằng ngày: phạt trả kết quả trễ hạn (50.000đ/ngày), nhắc Học thuật duyệt đề mỗi ngày từ 7 ngày trước,
 * tạo / đóng việc duyệt đề, lập biên bản khi GV chưa nhận đề sát ngày thi. Idempotent: chạy lại trong ngày không tạo thêm.
 */
class EnforceBigTestSlaCommand extends Command
{
    protected $signature = 'bigtests:enforce-sla';

    protected $description = 'SLA Big Test: phạt trả kết quả trễ, nhắc / giao việc duyệt đề, biên bản chưa nhận đề';

    public function handle(BigTestSlaService $sla): int
    {
        $late = $sla->enforceLateResults();

        $this->info("Lập {$late} biên bản trả kết quả Big Test trễ hạn.");

        return self::SUCCESS;
    }
}
