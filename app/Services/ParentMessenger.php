<?php

namespace App\Services;

use App\Mail\ParentNotice;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tin gửi phụ huynh / khách (xác nhận lịch hẹn, xác nhận đã nhận học phí): chọn email thật để gửi và gửi qua SMTP
 * đã cấu hình. Không có email (hoặc gửi lỗi) thì nơi gọi chuyển sang kênh thủ công: người phụ trách sao chép nội dung
 * gửi qua Zalo.
 */
class ParentMessenger
{
    /** Email đầu tiên hợp lệ và là email thật (bỏ email đăng nhập hệ thống tự sinh cho học viên chưa có email). */
    public static function recipient(?string ...$emails): ?string
    {
        foreach ($emails as $email) {
            $email = trim((string) $email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && ! User::isGeneratedStudentEmail($email)) {
                return $email;
            }
        }

        return null;
    }

    /** "Thứ Bảy 04/10/2026" */
    public static function dayLabel(CarbonInterface $date): string
    {
        $weekday = $date->dayOfWeek === 0 ? 'Chủ nhật' : 'Thứ '.['Hai', 'Ba', 'Tư', 'Năm', 'Sáu', 'Bảy'][$date->dayOfWeek - 1];

        return $weekday.' '.$date->format('d/m/Y');
    }

    /** Gửi email; lỗi SMTP chỉ ghi log và trả false để nơi gọi chuyển sang gửi thủ công. */
    public function send(string $email, string $subject, string $body, ?string $actionUrl = null, ?string $actionText = null): bool
    {
        try {
            Mail::to($email)->send(new ParentNotice($subject, $body, $actionUrl, $actionText));

            return true;
        } catch (\Throwable $e) {
            Log::warning("Không gửi được email phụ huynh tới {$email}: ".$e->getMessage());

            return false;
        }
    }
}
