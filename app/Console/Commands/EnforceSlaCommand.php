<?php

namespace App\Console\Commands;

use App\Services\Sla\CrmSlaService;
use Illuminate\Console\Command;

class EnforceSlaCommand extends Command
{
    protected $signature = 'sla:enforce';

    protected $description = 'Quét SLA CRM: mở mốc + giao việc tự động, quá hạn thì lập biên bản phạt và báo Admin / người phụ trách (idempotent)';

    public function handle(CrmSlaService $crm): int
    {
        foreach ($crm->run() as $rule => $count) {
            $this->line("{$rule}: {$count}");
        }

        return self::SUCCESS;
    }
}
