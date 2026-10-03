<?php

namespace App\Console\Commands;

use App\Services\MaterialOrderService;
use Illuminate\Console\Command;

/**
 * Order học liệu Chờ xử lý / Đang xử lý đã qua hạn (due_at) → "Quá hạn" + báo người xử lý (Học vụ chi nhánh cho
 * đạo cụ / in ấn / GVNN; người có quyền xử lý học thuật cho học liệu học thuật). Idempotent: chạy lại không đổi gì
 * thêm và không báo lặp vì order đã "Quá hạn" không bị chọn nữa.
 */
class MarkOverdueMaterialOrdersCommand extends Command
{
    protected $signature = 'material-orders:mark-overdue';

    protected $description = 'Đánh dấu "Quá hạn" cho order học liệu chưa xử lý đã qua hạn và báo người xử lý';

    public function handle(MaterialOrderService $service): int
    {
        $count = $service->markOverdue();

        $this->info("Đã chuyển {$count} order học liệu sang Quá hạn.");

        return self::SUCCESS;
    }
}
