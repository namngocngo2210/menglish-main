<?php

namespace App\Console\Commands;

use App\Models\AdminNotification;
use App\Models\ClassModel;
use App\Models\CoverRequest;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Yêu cầu dạy thay còn "open" (chưa ai được duyệt) mà buổi đã đến giờ học → báo người có quyền điểm danh thay GV
 * (Học vụ...) để tự điểm danh. Chỉ báo người xem được lớp đó. Idempotent: mỗi yêu cầu nhắc một lần.
 */
class RemindUnfilledCoverCommand extends Command
{
    protected $signature = 'cover:remind-unfilled';

    protected $description = 'Nhắc Học vụ điểm danh các buổi dạy thay đã đến giờ mà chưa có giáo viên được duyệt';

    public function handle(): int
    {
        $recorders = User::permission('attendance_student.record_any')->where('is_active', true)->get();
        $notified = 0;

        CoverRequest::with('classSession.classModel')
            ->where('status', 'open')->whereNull('attendance_reminded_at')
            ->whereHas('classSession', fn ($s) => $s->where('status', 'scheduled')->whereDate('date', '<=', now()->toDateString()))
            ->get()
            ->filter(fn (CoverRequest $cover) => $cover->classSession->startsAt()->lte(now()))
            ->each(function (CoverRequest $cover) use ($recorders, &$notified) {
                $session = $cover->classSession;
                foreach ($recorders as $user) {
                    if (! ClassModel::visibleTo($user)->whereKey($session->class_id)->exists()) {
                        continue;
                    }
                    AdminNotification::create([
                        'user_id' => $user->id, 'type' => 'cover_unfilled',
                        'title' => 'Buổi dạy thay chưa có giáo viên: '.$session->classModel->name,
                        'message' => 'Buổi '.$session->date->format('d/m/Y').' '.$session->start_time->format('H:i').' đã đến giờ học nhưng chưa ai được duyệt dạy thay. Hãy điểm danh thay giáo viên.',
                        'data' => ['link' => route('teacher.attendance', $session->class_id)], 'is_read' => false,
                    ]);
                    $notified++;
                }
                $cover->update(['attendance_reminded_at' => now()]);
            });

        $this->info("Đã nhắc {$notified} người.");

        return self::SUCCESS;
    }
}
