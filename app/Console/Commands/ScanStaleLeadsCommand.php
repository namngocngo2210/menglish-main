<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;

class ScanStaleLeadsCommand extends Command
{
    protected $signature = 'crm:scan-stale-leads';
    protected $description = 'Quét và phát hiện các Lead CRM bị sót quá 24h chưa chuyển trạng thái để gửi thông báo cho Admin';

    public function handle(NotificationService $notificationService): int
    {
        $this->info('Đang bắt đầu quét các Lead CRM tồn đọng quá 24h...');
        $count = $notificationService->scanAndSyncStaleLeads();
        $this->info("Đã phát hiện và tạo cảnh báo cho {$count} Lead bị sót.");

        return Command::SUCCESS;
    }
}
