<?php

namespace App\Services\Crm;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\CrmTrialBooking;
use App\Models\User;
use App\Services\ParentMessenger;
use App\Services\PlacementPortalLinkService;

/**
 * Xác nhận lịch hẹn (test đầu vào / học thử) cho phụ huynh ngay khi Học vụ xác nhận lịch:
 * - Khách đã có email tại thời điểm xác nhận → gửi email xác nhận.
 * - Chưa có email (hoặc gửi lỗi) → thông báo cho người phụ trách khách kèm nội dung xác nhận để sao chép gửi Zalo;
 *   controller trả nội dung này về trang khách để hiện popup ngay cho người vừa xác nhận.
 */
class AppointmentConfirmation
{
    public const NOTIFICATION_TYPE = 'appointment_confirm_manual';

    public function __construct(private readonly ParentMessenger $messenger) {}

    /**
     * @return array{subject: string, text: string, link: string|null}
     */
    public function forTest(CrmCustomer $customer): array
    {
        $customer->loadMissing(['branch', 'assignedTest']);
        $at = $customer->appointment_at;
        $online = $customer->appointment_type === 'online';
        // Hẹn online: kèm link làm bài riêng của khách (có chữ ký, hạn 7 ngày) khi đã gán đề đang mở.
        $link = $online && $customer->assignedTest?->is_active
            ? app(PlacementPortalLinkService::class)->signedLinkForLead($customer->assignedTest, $customer)
            : null;

        $lines = [
            '• Thời gian: '.$at?->format('H:i').', '.($at ? ParentMessenger::dayLabel($at) : '—'),
            '• Hình thức: '.($online ? 'Làm bài online' : 'Làm bài tại '.$this->place($customer->branch)),
        ];
        if ($link) {
            $lines[] = '• Link làm bài: '.$link.' (hiệu lực '.PlacementPortalLinkService::LINK_TTL_DAYS.' ngày)';
        }

        return [
            'subject' => 'Xác nhận lịch test đầu vào — '.$customer->name,
            'text' => $this->wrap("MEnglish xác nhận lịch test đầu vào của {$customer->name}:", $lines, $customer->branch),
            'link' => $link,
        ];
    }

    /**
     * @return array{subject: string, text: string, link: string|null}
     */
    public function forTrial(CrmTrialBooking $booking): array
    {
        $booking->loadMissing(['session', 'classModel.branch', 'customer.branch']);
        $session = $booking->session;
        $branch = $booking->classModel?->branch ?? $booking->customer?->branch;
        $time = $session?->start_time?->format('H:i').($session?->end_time ? '–'.$session->end_time->format('H:i') : '');
        $name = $booking->customer?->name ?? 'học viên';

        return [
            'subject' => 'Xác nhận lịch học thử — '.$name,
            'text' => $this->wrap("MEnglish xác nhận lịch học thử của {$name}:", [
                '• Thời gian: '.$time.', '.($session?->date ? ParentMessenger::dayLabel($session->date) : '—'),
                '• Lớp: '.($booking->classModel?->name ?? '—'),
                '• Địa điểm: '.$this->place($branch),
            ], $branch),
            'link' => null,
        ];
    }

    /**
     * Gửi xác nhận cho khách. Trả về email đã gửi (null nếu chưa gửi được) và nội dung để sao chép gửi Zalo.
     *
     * @param  array{subject: string, text: string, link: string|null}  $message
     * @return array{emailed: string|null, email_failed: bool, text: string, phone: string|null}
     */
    public function deliver(CrmCustomer $customer, array $message, User $actor, string $historyType): array
    {
        $email = ParentMessenger::recipient($customer->email);
        $phone = $customer->parent_phone ?: $customer->phone;

        if ($email && $this->messenger->send($email, $message['subject'], $message['text'], $message['link'], $message['link'] ? 'Vào làm bài test' : null)) {
            CrmCustomerHistory::create([
                'customer_id' => $customer->id,
                'user_id' => $actor->id,
                'type' => $historyType,
                'content' => "Đã gửi email xác nhận lịch hẹn tới {$email}.",
            ]);

            return ['emailed' => $email, 'email_failed' => false, 'text' => $message['text'], 'phone' => $phone];
        }

        $reason = $email ? "Gửi email tới {$email} không thành công" : 'Khách chưa có email';
        AdminNotification::create([
            'user_id' => $customer->assigned_user_id ?: $actor->id,
            'type' => self::NOTIFICATION_TYPE,
            'title' => 'Gửi xác nhận lịch hẹn qua Zalo: '.$customer->name,
            'message' => $reason.'. Sao chép nội dung xác nhận và gửi phụ huynh qua Zalo'.($phone ? " ({$phone})" : '').'.',
            'data' => [
                'copy_text' => $message['text'],
                'crm_customer_id' => $customer->id,
                'link' => route('crm.customers.show', $customer->id),
            ],
            'is_read' => false,
        ]);
        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => $actor->id,
            'type' => $historyType,
            'content' => $reason.': đã nhắc người phụ trách gửi xác nhận lịch hẹn cho phụ huynh qua Zalo.',
        ]);

        return ['emailed' => null, 'email_failed' => (bool) $email, 'text' => $message['text'], 'phone' => $phone];
    }

    private function place(?Branch $branch): string
    {
        if (! $branch) {
            return 'trung tâm MEnglish';
        }

        return 'cơ sở '.$branch->name.($branch->address ? ' ('.$branch->address.')' : '');
    }

    /** @param  list<string>  $lines */
    private function wrap(string $intro, array $lines, ?Branch $branch): string
    {
        $contact = $branch?->phone ? " qua số {$branch->phone}" : '';

        return "Kính gửi Quý phụ huynh,\n{$intro}\n".implode("\n", $lines)
            ."\nNếu cần đổi lịch, phụ huynh vui lòng báo lại trung tâm{$contact}. Cảm ơn Quý phụ huynh!";
    }
}
