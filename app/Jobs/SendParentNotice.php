<?php

namespace App\Jobs;

use App\Mail\ParentNotice;
use App\Models\AdminNotification;
use App\Models\SystemSetting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email gửi phụ huynh / khách (ParentNotice) qua queue `notifications`. Hết số lần thử mà vẫn lỗi → tạo thông báo dự phòng
 * (nếu nơi gọi truyền) để người phụ trách sao chép nội dung gửi tin nhắn, như khi khách không có email.
 */
class SendParentNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>|null  $fallback  thuộc tính AdminNotification tạo khi gửi thất bại hẳn
     */
    public function __construct(
        public string $email,
        public string $subject,
        public string $body,
        public ?string $actionUrl = null,
        public ?string $actionText = null,
        public ?array $fallback = null,
    ) {
        $this->onQueue(SendHtmlMail::QUEUE);
        // Không gửi cho dữ liệu bị rollback (vd. phiếu thu SePay lỗi giữa chừng).
        $this->afterCommit();
    }

    public function handle(): void
    {
        SystemSetting::applyDynamicMailConfig();
        Mail::to($this->email)->send(new ParentNotice($this->subject, $this->body, $this->actionUrl, $this->actionText));
    }

    public function failed(?Throwable $e): void
    {
        Log::warning("Không gửi được email phụ huynh tới {$this->email}: ".$e?->getMessage());
        if ($this->fallback) {
            AdminNotification::create($this->fallback);
        }
    }
}
