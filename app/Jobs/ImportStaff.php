<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\StaffImportService;

/** Nhập nhân sự từ Excel (bản xem trước đã xác nhận) chạy nền: tạo tài khoản / bổ sung hồ sơ. */
class ImportStaff extends BackgroundTaskJob
{
    protected function auditDescription(): ?string
    {
        return 'Nhập nhân sự từ file '.$this->task->payload['preview']['file_name'];
    }

    protected function process(): array
    {
        $payload = $this->task->payload;
        $user = User::find($this->task->user_id);
        if (! $user) {
            return ['ok' => false, 'message' => 'Người nhập không còn tồn tại.'];
        }

        $preview = $payload['preview'];
        $result = app(StaffImportService::class)->import($preview, $preview['password_hash'], $user);
        $result['file_name'] = $preview['file_name'];
        $result['skipped'] = collect($preview['rows'])->filter(fn ($r) => $r['errors'] !== [])->count();

        // Không giữ mật khẩu (đã băm) và dữ liệu cá nhân của file trong bảng việc nền sau khi ghi xong.
        $this->task->update(['payload' => ['file_name' => $preview['file_name']]]);

        return [
            'ok' => true,
            'message' => "Đã tạo {$result['created']} tài khoản, bổ sung hồ sơ {$result['updated']} tài khoản.",
            'report' => $result,
        ];
    }

    protected function link(): string
    {
        return route('users.import', ['task' => $this->task->id]);
    }
}
