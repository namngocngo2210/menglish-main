<?php

namespace App\Console\Commands;

use App\Services\Kpi\KpiSheetService;
use Illuminate\Console\Command;

/**
 * Sang tháng mới tự tạo phiếu KPI tháng (Chờ duyệt) cho mọi nhân sự có vai trò được chấm KPI. Chạy hằng ngày, idempotent:
 * nhân sự đã có phiếu tháng này thì bỏ qua, nhân sự mới vào giữa tháng có phiếu từ hôm sau.
 */
class CreateKpiSheetsCommand extends Command
{
    protected $signature = 'kpi:create-sheets';

    protected $description = 'Tạo phiếu KPI tháng hiện tại cho nhân sự chưa có phiếu';

    public function handle(KpiSheetService $sheets): int
    {
        $created = $sheets->ensureSheets(now()->month, now()->year);
        $this->info("Đã tạo {$created} phiếu KPI tháng ".now()->format('m/Y').'.');

        return self::SUCCESS;
    }
}
