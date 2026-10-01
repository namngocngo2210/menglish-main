<?php

namespace App\Console\Commands;

use App\Models\AdminNotification;
use App\Models\Penalty;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Nhắc nộp phạt (chủ dự án chốt: nộp trong 2 ngày, quá hạn không nhận nộp trực tiếp — trừ lương).
 * Chạy hằng ngày: biên bản đã quyết phạt, chưa nộp, hạn nộp là ngày mai hoặc hôm nay → báo nhân sự vi phạm
 * (GV, CM, TA như nhau). Idempotent: mỗi biên bản nhận tối đa 1 lời nhắc cho mỗi ngày nhắc.
 */
class RemindPenaltyDueCommand extends Command
{
    protected $signature = 'penalties:remind-due';

    protected $description = 'Nhắc nhân sự nộp phạt khi hạn nộp là ngày mai hoặc hôm nay';

    public function handle(): int
    {
        $today = today();
        $tomorrow = $today->copy()->addDay();
        $created = 0;

        Penalty::query()
            ->where('status', 'fined')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '>=', $today->toDateString())
            ->whereDate('due_date', '<=', $tomorrow->toDateString())
            ->orderBy('id')
            ->chunkById(200, function ($penalties) use ($today, &$created) {
                foreach ($penalties as $penalty) {
                    $exists = AdminNotification::query()
                        ->where('type', 'penalty_due_reminder')
                        ->where('user_id', $penalty->user_id)
                        ->where('data->penalty_id', $penalty->id)
                        ->where('data->remind_on', $today->toDateString())
                        ->exists();
                    if ($exists) {
                        continue;
                    }

                    $dueToday = $penalty->due_date->isSameDay($today);
                    AdminNotification::create([
                        'user_id' => $penalty->user_id,
                        'type' => 'penalty_due_reminder',
                        'title' => "Biên bản {$penalty->code}: hạn nộp phạt ".($dueToday ? 'là hôm nay' : 'là ngày mai'),
                        'message' => 'Nộp '.Money::format((float) $penalty->amount).' trước hết ngày '.$penalty->due_date->format('d/m/Y')
                            .' — quá hạn không nhận nộp trực tiếp, khoản phạt sẽ trừ vào lương kỳ này.',
                        'data' => [
                            'penalty_id' => $penalty->id,
                            'remind_on' => $today->toDateString(),
                            'link' => route('penalties.index', ['search' => $penalty->code]),
                        ],
                        'is_read' => false,
                    ]);
                    $created++;
                }
            });

        $this->info("Đã gửi {$created} lời nhắc nộp phạt.");

        return self::SUCCESS;
    }
}
