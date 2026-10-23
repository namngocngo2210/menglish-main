<?php

namespace App\Console\Commands;

use App\Models\SyllabusAssignment;
use App\Services\Syllabus\SyllabusNotifier;
use Illuminate\Console\Command;

/**
 * Chặng đang mở mà GV chưa XÁC NHẬN sau 24h kể từ khi giao → nhắc GV + báo Học thuật và Quản lý chi nhánh (Bước 4, câu 4
 * — docs/kho-syllabus.md §7.3). Chỉ nhắc 1 lần (confirm_reminded_at), chạy lại không nhắc lặp.
 */
class RemindUnconfirmedSyllabusCommand extends Command
{
    protected $signature = 'syllabus:remind-unconfirmed';

    protected $description = 'Nhắc GV chưa xác nhận syllabus sau 24 giờ, báo Học thuật và Quản lý';

    public function handle(SyllabusNotifier $notifier): int
    {
        $count = 0;
        SyllabusAssignment::open()->with('classModel')
            ->whereNull('confirmed_at')->whereNull('confirm_reminded_at')
            ->where('opened_at', '<=', now()->subDay())
            ->orderBy('id')
            ->each(function (SyllabusAssignment $assignment) use ($notifier, &$count) {
                $assignment->update(['confirm_reminded_at' => now()]);
                $notifier->unconfirmedReminder($assignment);
                $count++;
            });

        $this->info("Đã nhắc {$count} chặng chưa xác nhận.");

        return self::SUCCESS;
    }
}
