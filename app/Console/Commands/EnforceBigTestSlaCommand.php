<?php

namespace App\Console\Commands;

use App\Services\BigTestSlaService;
use Illuminate\Console\Command;

/**
 * SLA Big Test hằng ngày: phạt trả kết quả trễ hạn (50.000đ/ngày), tự tạo / đóng việc duyệt đề, lập biên bản khi GV chưa nhận đề sát ngày thi (dưới 24h). Idempotent: chạy lại không tạo thêm.
 */
class EnforceBigTestSlaCommand extends Command
{
    protected $signature = 'bigtests:enforce-sla {--paper-only : Chỉ lập biên bản GV chưa nhận đề (chạy mỗi giờ để bắt đúng mốc 24h)}';

    protected $description = 'SLA Big Test: phạt trả kết quả trễ, nhắc / giao việc duyệt đề, biên bản chưa nhận đề';

    public function handle(BigTestSlaService $sla): int
    {
        $paper = $sla->enforcePaperMissing();
        if ($this->option('paper-only')) {
            $this->info("Lập {$paper} biên bản GV chưa nhận đề Big Test.");

            return self::SUCCESS;
        }

        $late = $sla->enforceLateResults();
        $tasks = $sla->ensureApprovalTasks();
        $closed = $sla->closeApprovalTasks();

        $this->info("Biên bản: {$late} trả kết quả trễ, {$paper} GV chưa nhận đề. Việc duyệt đề: tạo {$tasks}, đóng {$closed}.");

        return self::SUCCESS;
    }
}
