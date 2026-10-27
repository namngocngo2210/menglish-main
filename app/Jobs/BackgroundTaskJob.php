<?php

namespace App\Jobs;

use App\Models\AdminNotification;
use App\Models\BackgroundTask;
use App\Support\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Khung cho việc nặng chạy nền ở queue default: đánh dấu đang chạy → process() → lưu kết quả vào background_tasks, xoá file
 * tải lên, báo người chạy (thông báo trong app, kèm link về màn khởi chạy). Chạy một lần (không thử lại): chạy lại = người
 * dùng bấm lại. Lỗi ngoài dự kiến / quá thời gian → failed() ghi trạng thái Lỗi.
 */
abstract class BackgroundTaskJob implements ShouldQueue
{
    use Queueable;

    public const NOTIFICATION_TYPE = 'background_task';

    public int $tries = 1;

    /** Giây; worker queue (docker-compose: queue) và DB_QUEUE_RETRY_AFTER đặt lớn hơn. */
    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(public BackgroundTask $task) {}

    /**
     * Làm việc chính. Lỗi nghiệp vụ (file sai, dữ liệu không hợp lệ) trả ok = false kèm message, không ném lỗi.
     *
     * @return array{ok: bool, message: string, report?: array<string, mixed>}
     */
    abstract protected function process(): array;

    /** Màn để người chạy xem kết quả. */
    abstract protected function link(): string;

    /** Mô tả ghi vào nhật ký thao tác cho các thay đổi của lần chạy (như Audit::describe ở controller). */
    protected function auditDescription(): ?string
    {
        return null;
    }

    public function handle(): void
    {
        $this->task->update(['status' => BackgroundTask::RUNNING, 'started_at' => now()]);

        // Chạy dưới danh nghĩa người bấm: nhật ký thao tác ghi đúng người thực hiện như khi chạy trong request.
        $previous = Auth::user();
        if ($this->task->user) {
            Auth::setUser($this->task->user);
        }
        if ($description = $this->auditDescription()) {
            Audit::describe($description);
        }

        try {
            $result = $this->process();
        } finally {
            $this->cleanup();
            Audit::clearDescription();
            $previous ? Auth::setUser($previous) : Auth::forgetUser();
        }

        $this->finish($result['ok'] ? BackgroundTask::DONE : BackgroundTask::FAILED, $result['message'], $result['report'] ?? null);
    }

    public function failed(?Throwable $e): void
    {
        $this->cleanup();
        $this->finish(BackgroundTask::FAILED, 'Bị lỗi hệ thống hoặc quá thời gian. Thử lại, hoặc báo quản trị nếu vẫn lỗi.');
    }

    /** Đường dẫn file đã lưu thứ $index của task (disk local). */
    protected function storedFile(int $index = 0): string
    {
        return Storage::disk('local')->path($this->task->directory().'/'.$index);
    }

    private function cleanup(): void
    {
        Storage::disk('local')->deleteDirectory($this->task->directory());
    }

    private function finish(string $status, string $message, ?array $report = null): void
    {
        $this->task->update(['status' => $status, 'message' => $message, 'report' => $report, 'finished_at' => now()]);

        if ($this->task->user_id) {
            AdminNotification::create([
                'user_id' => $this->task->user_id,
                'type' => self::NOTIFICATION_TYPE,
                'title' => ($status === BackgroundTask::DONE ? 'Xong: ' : 'Không thành công: ').$this->task->title,
                'message' => $message,
                'data' => ['link' => $this->link()],
                'is_read' => false,
            ]);
        }
    }
}
