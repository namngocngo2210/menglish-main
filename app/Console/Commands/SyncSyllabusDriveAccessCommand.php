<?php

namespace App\Console\Commands;

use App\Services\Syllabus\DriveAccessSync;
use Illuminate\Console\Command;

/**
 * Mở / đóng quyền xem file Google Drive theo chặng lớp đang học (docs/kho-syllabus.md §7.4). Chưa cấu hình service account
 * → không làm gì. Idempotent, chạy mỗi phút (repo không có queue worker).
 */
class SyncSyllabusDriveAccessCommand extends Command
{
    protected $signature = 'syllabus:sync-drive-access';

    protected $description = 'Đồng bộ quyền xem file Google Drive của syllabus theo chặng lớp đang học';

    public function handle(DriveAccessSync $sync): int
    {
        if (! $sync->configured()) {
            $this->info('Chưa cấu hình Google Drive (GOOGLE_DRIVE_SA_KEY_PATH) — bỏ qua.');

            return self::SUCCESS;
        }
        $result = $sync->sync();
        $this->info("Mở {$result['granted']} quyền, thu hồi {$result['revoked']} quyền, lỗi {$result['failed']}.");

        return self::SUCCESS;
    }
}
