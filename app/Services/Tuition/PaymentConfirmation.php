<?php

namespace App\Services\Tuition;

use App\Models\AcademicRecord;
use App\Models\AdminNotification;
use App\Models\CrmCustomer;
use App\Models\TuitionReceipt;
use App\Services\ParentMessenger;
use App\Support\Money;
use App\Support\Portal\PortalNotifications;
use Carbon\Carbon;

/**
 * Báo phụ huynh "đã nhận tiền" khi một khoản học phí được ghi nhận (SePay khớp lệnh):
 * - Luôn tạo thông báo ở Cổng Học viên tương ứng.
 * - Có email thật (email học viên, không thì email khách CRM đã chốt) → gửi email xác nhận.
 * - Không có email / gửi lỗi → thông báo cho người phụ trách khách kèm nội dung để sao chép gửi Zalo khi cần.
 * Nội dung chỉ nêu số tiền đã nhận và số còn phải đóng, không liệt kê các khoản "Thu khác".
 * Mỗi phiếu thu chỉ báo một lần (record_code PAYCONFIRM-{id}).
 */
class PaymentConfirmation
{
    public const NOTIFICATION_TYPE = 'payment_confirm_manual';

    public function __construct(private readonly ParentMessenger $messenger) {}

    /**
     * @return array{sent: bool, emailed: string|null, email_failed: bool, text: string}
     */
    public function notify(TuitionReceipt $receipt): array
    {
        $receipt->loadMissing(['tuition.student', 'tuition.classModel', 'student']);
        $student = $receipt->tuition?->student ?? $receipt->student;
        if (! $student) {
            return ['sent' => false, 'emailed' => null, 'email_failed' => false, 'text' => ''];
        }

        $text = $this->text($receipt);
        $record = AcademicRecord::firstOrCreate(
            ['screen_key' => PortalNotifications::SCREEN_KEY, 'record_code' => 'PAYCONFIRM-'.$receipt->id],
            [
                'module' => 'student_portal',
                'title' => 'Đã nhận học phí '.Money::format((float) $receipt->amount),
                'status' => 'active',
                'data' => [
                    'type' => 'fee_payment',
                    'title' => 'Đã nhận học phí',
                    'content' => $text,
                    'unread' => true,
                    'icon' => 'paid',
                    'bg_color' => 'bg-tertiary/10',
                    'text_color' => 'text-tertiary',
                    'student_id' => (string) $student->id,
                    'receipt_id' => $receipt->id,
                    'created_at' => now()->toDateTimeString(),
                ],
                'user_id' => $student->user_id,
            ]
        );
        if (! $record->wasRecentlyCreated) {
            return ['sent' => false, 'emailed' => null, 'email_failed' => false, 'text' => $text];
        }

        $customer = CrmCustomer::query()->where('converted_student_id', $student->id)->latest('id')->first();
        $email = ParentMessenger::recipient($student->email, $customer?->email);
        $subject = 'Xác nhận đã nhận học phí — '.$student->name;
        if ($email && $this->messenger->send($email, $subject, $text)) {
            return ['sent' => true, 'emailed' => $email, 'email_failed' => false, 'text' => $text];
        }

        if ($ownerId = $customer?->assigned_user_id) {
            $phone = $student->parent_phone ?: $student->phone;
            AdminNotification::create([
                'user_id' => $ownerId,
                'type' => self::NOTIFICATION_TYPE,
                'title' => 'Đã nhận học phí của '.$student->name.' ('.Money::format((float) $receipt->amount).')',
                'message' => ($email ? "Gửi email tới {$email} không thành công" : 'Học viên chưa có email')
                    .'; đã báo ở Cổng Học viên. Nếu cần, sao chép nội dung và xác nhận với phụ huynh qua Zalo'.($phone ? " ({$phone})" : '').'.',
                'data' => [
                    'copy_text' => $text,
                    'student_id' => $student->id,
                    'receipt_id' => $receipt->id,
                    'link' => route('tuition.history'),
                ],
                'is_read' => false,
            ]);
        }

        return ['sent' => true, 'emailed' => null, 'email_failed' => (bool) $email, 'text' => $text];
    }

    public function text(TuitionReceipt $receipt): string
    {
        $tuition = $receipt->tuition;
        $student = $tuition?->student ?? $receipt->student;
        $date = $receipt->payment_date ? Carbon::parse($receipt->payment_date)->format('d/m/Y') : now()->format('d/m/Y');
        $debt = (float) ($tuition?->fresh()?->debt_amount ?? 0);
        $class = $tuition?->classModel?->name;

        return "Kính gửi Quý phụ huynh,\n"
            .'MEnglish xác nhận đã nhận '.Money::format((float) $receipt->amount)." học phí của học viên {$student?->name}"
            .($student?->code ? " ({$student->code})" : '').($class ? " — lớp {$class}" : '')." ngày {$date}.\n"
            ."• Mã phiếu thu: {$receipt->receipt_number}\n"
            .($debt > 0 ? '• Học phí còn phải đóng: '.Money::format($debt) : '• Học viên đã hoàn tất học phí của hợp đồng này.')
            ."\nCảm ơn Quý phụ huynh đã tin tưởng MEnglish!";
    }
}
