<?php

namespace App\Console\Commands;

use App\Services\Syllabus\Import\SyllabusImportStore;
use Illuminate\Console\Command;

/**
 * Xoá file tạm import giáo trình (gói upload bỏ dở, thư mục giải nén, zip mẫu) quá 1 ngày.
 * Idempotent: chạy lại không đổi gì thêm.
 */
class PruneSyllabusImportsCommand extends Command
{
    protected $signature = 'syllabus:prune-imports';

    protected $description = 'Xoá file tạm import giáo trình quá 1 ngày';

    public function handle(SyllabusImportStore $store): int
    {
        $count = $store->prune(now()->subDay());

        $this->info("Đã xoá {$count} mục file tạm import giáo trình.");

        return self::SUCCESS;
    }
}
