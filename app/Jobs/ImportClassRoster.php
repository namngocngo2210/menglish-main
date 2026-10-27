<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ClassRosterImportService;
use Carbon\Carbon;

/** Nhập danh sách lớp từ hệ thống cũ (bản xem trước đã xác nhận) chạy nền: lớp, phòng, giáo viên, học viên, xếp lớp, sinh buổi học. */
class ImportClassRoster extends BackgroundTaskJob
{
    protected function process(): array
    {
        $preview = $this->task->payload['preview'];
        $user = User::find($this->task->user_id);
        if (! $user) {
            return ['ok' => false, 'message' => 'Người nhập không còn tồn tại.'];
        }

        $result = app(ClassRosterImportService::class)->import($preview, $user, Carbon::parse($preview['sessions_from']), Carbon::parse($preview['sessions_to']));
        $result['file_name'] = $preview['file_name'];
        $result['skipped'] = collect($preview['students'])->filter(fn ($s) => $s['errors'] !== [] || $s['action'] === 'skip')->count();

        $this->task->update(['payload' => ['file_name' => $preview['file_name']]]);

        return [
            'ok' => true,
            'message' => "Đã tạo {$result['classes']} lớp, {$result['rooms']} phòng học, gán giáo viên cho {$result['teachers']} lớp, {$result['students']} học viên và xếp {$result['enrollments']} lượt vào lớp.",
            'report' => $result,
        ];
    }

    protected function link(): string
    {
        return route('classes.import', ['task' => $this->task->id]);
    }
}
