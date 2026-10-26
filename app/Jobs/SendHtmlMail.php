<?php

namespace App\Jobs;

use App\Models\SystemSetting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Gửi một email thông báo nội bộ (ticket, cảnh báo vận hành, SePay...) qua queue `notifications`: request không phải chờ SMTP,
 * mỗi người nhận một job, SMTP lỗi thì thử lại. Nội dung đã dựng sẵn ở nơi gọi.
 */
class SendHtmlMail implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'notifications';

    public int $tries = 3;

    /** @var array<int, int> giây chờ trước lần thử 2, 3 */
    public array $backoff = [60, 300];

    public int $timeout = 60;

    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
        public bool $plainText = false,
    ) {
        $this->onQueue(self::QUEUE);
        // Không gửi cho dữ liệu bị rollback (vd. phiếu thu SePay lỗi giữa chừng).
        $this->afterCommit();
    }

    public function handle(): void
    {
        // Cấu hình SMTP lưu trong DB (màn Cấu hình hệ thống); worker sống lâu nên nạp lại mỗi lần gửi.
        SystemSetting::applyDynamicMailConfig();

        $callback = fn ($message) => $message->to($this->to)->subject($this->subject);
        $this->plainText ? Mail::raw($this->body, $callback) : Mail::html($this->body, $callback);
    }

    public function failed(?Throwable $e): void
    {
        Log::warning("Không gửi được email [{$this->subject}] tới {$this->to}: ".$e?->getMessage());
    }
}
