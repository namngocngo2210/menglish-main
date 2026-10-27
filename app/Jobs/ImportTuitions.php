<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\TuitionImportService;

/** Nhập học phí từ Excel (các dòng hợp lệ của bản xem trước) chạy nền: tạo hồ sơ học phí + phiếu thu chờ duyệt. */
class ImportTuitions extends BackgroundTaskJob
{
    protected function process(): array
    {
        $payload = $this->task->payload;
        $user = User::find($this->task->user_id);
        if (! $user) {
            return ['ok' => false, 'message' => 'Người nhập không còn tồn tại.'];
        }

        $result = app(TuitionImportService::class)->import($payload['rows'], (int) $payload['branch_id'], $user);

        return [
            'ok' => true,
            'message' => "Đã nhập {$result['tuitions']} hồ sơ học phí và {$result['receipts']} phiếu thu chờ duyệt.",
            'report' => [
                'file_name' => $payload['file_name'],
                'tuitions' => $result['tuitions'],
                'receipts' => $result['receipts'],
                'skipped' => $payload['skipped'],
                'failed' => $result['failed'],
            ],
        ];
    }

    protected function link(): string
    {
        return route('tuition.import', ['task' => $this->task->id]);
    }
}
