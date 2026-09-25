<?php

namespace App\Services;

use App\Models\AcademicRecord;
use App\Models\AdminNotification;
use App\Models\CrmCustomer;
use App\Models\DebtReminderRule;
use App\Models\PlacementTestSubmission;
use App\Models\StudentTuition;
use App\Models\SupportTicket;
use App\Models\SystemSetting;
use App\Models\TicketMessage;
use App\Models\TuitionReceipt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class NotificationService
{
    /**
     * Quét và tạo thông báo cho các Lead bị sót quá 24h chưa chuyển trạng thái
     */
    public function scanAndSyncStaleLeads(): int
    {
        $cutoffTime = Carbon::now()->subHours(24);

        // Tìm các lead ở trạng thái 'new' được tạo trước mốc 24h
        $staleLeads = CrmCustomer::with('assignedUser')
            ->where('stage', 'new')
            ->where('created_at', '<=', $cutoffTime)
            ->get();

        $generatedCount = 0;

        foreach ($staleLeads as $lead) {
            $hoursElapsed = round(Carbon::parse($lead->created_at)->diffInHours(now()));

            // Mỗi lead chỉ cảnh báo một lần: lead không thể quay về stage 'new',
            // nên đủ điều kiện kiểm tra tồn tại thông báo bất kể đã đọc hay chưa
            // (check is_read sẽ tái tạo thông báo + gửi lại email sau khi admin đọc).
            $existingNotif = AdminNotification::where('type', 'stale_lead_24h')
                ->where('data->customer_id', $lead->id)
                ->exists();

            if (! $existingNotif) {
                AdminNotification::create([
                    'type' => 'stale_lead_24h',
                    'title' => "⚠️ Cảnh báo: Lead {$lead->code} bị sót quá {$hoursElapsed}h chưa xử lý!",
                    'message' => "Khách hàng {$lead->name} (SĐT: {$lead->phone}) được tiếp nhận từ {$lead->created_at->format('d/m/Y H:i')} ({$hoursElapsed} giờ trước) nhưng vẫn ở trạng thái 'Mới tiếp nhận' và chưa được liên hệ chăm sóc.",
                    'data' => [
                        'customer_id' => $lead->id,
                        'customer_code' => $lead->code,
                        'customer_name' => $lead->name,
                        'customer_phone' => $lead->phone,
                        'hours_elapsed' => $hoursElapsed,
                        'assigned_user' => $lead->assignedUser?->name ?? 'Chưa phân công',
                        'link' => route('crm.customers.show', $lead->id),
                    ],
                    'is_read' => false,
                ]);
                $generatedCount++;

                // Bắn email cảnh báo vận hành cho danh sách email nhận thông báo
                $this->sendOperationalAlertEmail(
                    'stale_lead',
                    "[CRM] Cảnh báo: Lead #{$lead->code} ({$lead->name}) sót quá {$hoursElapsed}h",
                    "Khách hàng {$lead->name} (SĐT: {$lead->phone}) được tiếp nhận từ ".Carbon::parse($lead->created_at)->format('d/m/Y H:i')." ({$hoursElapsed} giờ trước) nhưng vẫn ở trạng thái 'Mới tiếp nhận' và chưa được nhân sự liên hệ chăm sóc.\nNhân sự phụ trách: ".($lead->assignedUser?->name ?? 'Chưa phân công'),
                    [
                        'code' => $lead->code,
                        'title' => "[CRM] Cảnh báo Lead sót: {$lead->name}",
                        'status' => 'overdue',
                        'status_label' => "Sót {$hoursElapsed}h",
                        'priority' => 'urgent',
                        'priority_label' => 'Cần xử lý gấp',
                        'category_label' => 'CRM & Tuyển sinh',
                        'sender_name' => 'Hệ thống Quét CRM',
                        'action_url' => route('crm.customers.show', $lead->id),
                        'action_text' => "Chăm sóc Lead #{$lead->code}",
                    ]
                );
            }
        }

        return $generatedCount;
    }

    /**
     * Lấy số lượng thông báo chưa đọc theo người dùng đang đăng nhập
     */
    public function getUnreadCount(?User $user = null): int
    {
        if (! $user) {
            $user = auth()->user();
        }
        if (! $user) {
            return 0;
        }

        $query = AdminNotification::where('is_read', false);
        $isGlobalViewer = $user->hasRole('admin') || $user->hasRole('manager') || $user->roles->isEmpty();

        if ($isGlobalViewer) {
            $query->where(function ($q) use ($user) {
                $q->whereNull('user_id')->orWhere('user_id', $user->id);
            });
        } else {
            $query->where('user_id', $user->id);
        }

        return $query->count();
    }

    /**
     * Lấy danh sách thông báo gần nhất theo người dùng
     */
    public function getUserNotifications(?User $user = null, int $limit = 10)
    {
        if (! $user) {
            $user = auth()->user();
        }
        if (! $user) {
            return collect();
        }

        // Việc quét stale lead chỉ chạy qua command định kỳ (crm:scan-stale-leads),
        // không tự chạy khi đọc danh sách thông báo để tránh query + email lặp lại.
        $isGlobalViewer = $user->hasRole('admin') || $user->hasRole('manager') || $user->roles->isEmpty();

        $query = AdminNotification::latest();

        if ($isGlobalViewer) {
            $query->where(function ($q) use ($user) {
                $q->whereNull('user_id')->orWhere('user_id', $user->id);
            });
        } else {
            $query->where('user_id', $user->id);
        }

        return $query->take($limit)->get();
    }

    /**
     * Lấy danh sách thông báo gần nhất (dùng cho tương thích cũ)
     */
    public function getRecentNotifications($limit = 10)
    {
        return $this->getUserNotifications(auth()->user(), $limit);
    }

    /**
     * Thông báo khi có Ticket báo lỗi mới được tạo
     */
    public function notifyTicketCreated(SupportTicket $ticket): void
    {
        $creator = $ticket->creator;
        $creatorName = $creator?->name ?? 'Hệ thống';

        // 1. Nếu có người được phân công trực tiếp (assignee), thông báo riêng cho người đó
        if ($ticket->assignee_id && $ticket->assignee_id !== $ticket->creator_id) {
            AdminNotification::create([
                'user_id' => $ticket->assignee_id,
                'type' => 'ticket_assigned',
                'title' => "[#{$ticket->code}] {$ticket->title}",
                'message' => "{$creatorName} đã giao cho bạn: ".Str::limit(strip_tags($ticket->description), 120),
                'data' => [
                    'ticket_id' => $ticket->id,
                    'ticket_code' => $ticket->code,
                    'ticket_title' => $ticket->title,
                    'creator_name' => $creatorName,
                    'link' => route('tickets.show', $ticket->id),
                ],
                'is_read' => false,
            ]);
        }

        // 2. Thông báo cho Ban Quản trị / Quản lý xử lý (user_id = null)
        AdminNotification::create([
            'user_id' => null,
            'type' => 'ticket_new',
            'title' => "[#{$ticket->code}] {$ticket->title}",
            'message' => "{$creatorName}: ".Str::limit(strip_tags($ticket->description), 120),
            'data' => [
                'ticket_id' => $ticket->id,
                'ticket_code' => $ticket->code,
                'ticket_title' => $ticket->title,
                'creator_name' => $creatorName,
                'link' => route('tickets.show', $ticket->id),
            ],
            'is_read' => false,
        ]);

        // 3. Bắn Email SMTP thông báo đến email kỹ thuật / hỗ trợ
        if (SystemSetting::isTicketEventEnabled('created')) {
            $techEmails = SystemSetting::getTicketEmails();
            if (! empty($techEmails)) {
                try {
                    $emailSubject = "[#{$ticket->code}] {$ticket->title}";
                    $htmlBody = view('emails.ticket-notification', [
                        'ticket' => $ticket,
                        'type' => 'new',
                        'subjectTitle' => $emailSubject,
                        'notificationTypeLabel' => 'Yêu cầu mới',
                        'priorityLabel' => $ticket->priority_label,
                        'content' => strip_tags($ticket->description),
                        'senderName' => $creatorName,
                        'actionUrl' => route('tickets.show', $ticket->id),
                        'actionText' => "Xử lý Ticket #{$ticket->code}",
                    ])->render();

                    foreach ($techEmails as $techEmail) {
                        Mail::html($htmlBody, function ($message) use ($techEmail, $emailSubject) {
                            $message->to($techEmail)
                                ->subject($emailSubject);
                        });
                    }
                } catch (\Throwable $e) {
                    Log::warning("Không thể gửi email thông báo ticket #{$ticket->code}: ".$e->getMessage());
                }
            }
        }
    }

    /**
     * Thông báo khi có phản hồi / tin nhắn mới trong luồng Ticket
     * (Chỉ những người nằm trong luồng ticket đó mới nhận được thông báo)
     */
    public function notifyTicketMessage(SupportTicket $ticket, TicketMessage $message, User $sender): void
    {
        $recipientIds = collect();

        // 1. Thêm người tạo ticket (nếu người gửi không phải là người tạo)
        if ($ticket->creator_id && $ticket->creator_id !== $sender->id) {
            $recipientIds->push($ticket->creator_id);
        }

        // 2. Thêm người đang được phân công xử lý (nếu có và không phải là người gửi)
        if ($ticket->assignee_id && $ticket->assignee_id !== $sender->id) {
            $recipientIds->push($ticket->assignee_id);
        }

        // 3. Thêm tất cả những người từng tham gia trả lời / bình luận trong ticket này
        $participantIds = $ticket->messages()
            ->where('user_id', '!=', $sender->id)
            ->pluck('user_id');
        $recipientIds = $recipientIds->merge($participantIds)->unique();

        $preview = Str::limit(strip_tags($message->message), 100);

        // 4. Nếu ticket chưa có assignee và người gửi là creator, gửi thông báo chung cho Admin/Manager
        if (! $ticket->assignee_id && $ticket->creator_id === $sender->id) {
            AdminNotification::create([
                'user_id' => null,
                'type' => 'ticket_message',
                'title' => "💬 Phản hồi mới trên Ticket #{$ticket->code}",
                'message' => "{$sender->name}: \"{$preview}\"",
                'data' => [
                    'ticket_id' => $ticket->id,
                    'ticket_code' => $ticket->code,
                    'ticket_title' => $ticket->title,
                    'sender_name' => $sender->name,
                    'link' => route('tickets.show', $ticket->id),
                ],
                'is_read' => false,
            ]);
        }

        // Tạo thông báo cá nhân cho từng người liên quan trong luồng
        foreach ($recipientIds as $userId) {
            AdminNotification::create([
                'user_id' => $userId,
                'type' => 'ticket_message',
                'title' => "Re: [#{$ticket->code}] {$ticket->title}",
                'message' => "{$sender->name}: {$preview}",
                'data' => [
                    'ticket_id' => $ticket->id,
                    'ticket_code' => $ticket->code,
                    'ticket_title' => $ticket->title,
                    'sender_name' => $sender->name,
                    'link' => route('tickets.show', $ticket->id),
                ],
                'is_read' => false,
            ]);
        }

        // 5. Bắn Email SMTP cho các bên liên quan trong luồng trao đổi
        $recipientEmails = User::whereIn('id', $recipientIds)
            ->whereNotNull('email')
            ->pluck('email')
            ->toArray();

        if (SystemSetting::isTicketEventEnabled('comment')) {
            $techEmails = SystemSetting::getTicketEmails();
            foreach ($techEmails as $techEmail) {
                $recipientEmails[] = $techEmail;
            }
        }

        $recipientEmails = array_values(array_unique(array_filter($recipientEmails)));

        if (! empty($recipientEmails)) {
            try {
                $emailSubject = "Re: [#{$ticket->code}] {$ticket->title}";
                $htmlBody = view('emails.ticket-notification', [
                    'ticket' => $ticket,
                    'type' => 'reply',
                    'subjectTitle' => $emailSubject,
                    'notificationTypeLabel' => 'Phản hồi mới',
                    'priorityLabel' => $ticket->priority_label,
                    'content' => strip_tags($message->message),
                    'senderName' => $sender->name,
                    'actionUrl' => route('tickets.show', $ticket->id),
                    'actionText' => "Phản hồi Ticket #{$ticket->code}",
                ])->render();

                foreach ($recipientEmails as $email) {
                    Mail::html($htmlBody, function ($mail) use ($email, $emailSubject) {
                        $mail->to($email)->subject($emailSubject);
                    });
                }
            } catch (\Throwable $e) {
                Log::warning("Không thể gửi email phản hồi ticket #{$ticket->code}: ".$e->getMessage());
            }
        }
    }

    /**
     * Thông báo khi trạng thái ticket thay đổi
     */
    public function notifyTicketStatusChanged(SupportTicket $ticket, string $status, User $actor): void
    {
        $recipientIds = collect();

        if ($ticket->creator_id && $ticket->creator_id !== $actor->id) {
            $recipientIds->push($ticket->creator_id);
        }
        if ($ticket->assignee_id && $ticket->assignee_id !== $actor->id) {
            $recipientIds->push($ticket->assignee_id);
        }

        $recipientIds = $recipientIds->unique();

        foreach ($recipientIds as $userId) {
            AdminNotification::create([
                'user_id' => $userId,
                'type' => 'ticket_status',
                'title' => "Re: [#{$ticket->code}] [{$ticket->status_label}] {$ticket->title}",
                'message' => "{$actor->name} đã cập nhật sang {$ticket->status_label}.",
                'data' => [
                    'ticket_id' => $ticket->id,
                    'ticket_code' => $ticket->code,
                    'ticket_title' => $ticket->title,
                    'actor_name' => $actor->name,
                    'link' => route('tickets.show', $ticket->id),
                ],
                'is_read' => false,
            ]);
        }

        // Bắn email thông báo đổi trạng thái
        $recipientEmails = User::whereIn('id', $recipientIds)->whereNotNull('email')->pluck('email')->toArray();
        if (SystemSetting::isTicketEventEnabled('status_changed')) {
            $techEmails = SystemSetting::getTicketEmails();
            foreach ($techEmails as $techEmail) {
                $recipientEmails[] = $techEmail;
            }
        }
        $recipientEmails = array_values(array_unique(array_filter($recipientEmails)));

        if (! empty($recipientEmails)) {
            try {
                $emailSubject = "Re: [#{$ticket->code}] [{$ticket->status_label}] {$ticket->title}";
                $htmlBody = view('emails.ticket-notification', [
                    'ticket' => $ticket,
                    'type' => 'status',
                    'subjectTitle' => $emailSubject,
                    'notificationTypeLabel' => 'Cập nhật tiến độ',
                    'priorityLabel' => $ticket->priority_label,
                    'content' => "{$actor->name} đã cập nhật trạng thái thành: {$ticket->status_label}",
                    'senderName' => $actor->name,
                    'actionUrl' => route('tickets.show', $ticket->id),
                    'actionText' => "Xem Ticket #{$ticket->code}",
                ])->render();

                foreach ($recipientEmails as $email) {
                    Mail::html($htmlBody, function ($mail) use ($email, $emailSubject) {
                        $mail->to($email)->subject($emailSubject);
                    });
                }
            } catch (\Throwable $e) {
                Log::warning("Không thể gửi email trạng thái ticket #{$ticket->code}: ".$e->getMessage());
            }
        }
    }

    /**
     * Thông báo khi phân công lại ticket cho nhân sự khác
     */
    public function notifyTicketAssigned(SupportTicket $ticket, User $newAssignee, User $actor): void
    {
        if ($newAssignee->id !== $actor->id) {
            AdminNotification::create([
                'user_id' => $newAssignee->id,
                'type' => 'ticket_assigned',
                'title' => "[#{$ticket->code}] {$ticket->title}",
                'message' => "{$actor->name} đã phân công xử lý cho bạn.",
                'data' => [
                    'ticket_id' => $ticket->id,
                    'ticket_code' => $ticket->code,
                    'ticket_title' => $ticket->title,
                    'actor_name' => $actor->name,
                    'link' => route('tickets.show', $ticket->id),
                ],
                'is_read' => false,
            ]);

            if ($newAssignee->email) {
                try {
                    $emailSubject = "🎫 [MEnglish Phân công] Ticket #{$ticket->code}: {$ticket->title}";
                    $emailBody = "Chào {$newAssignee->name},\n\n"
                        ."Bạn vừa được phân công xử lý Ticket sau:\n\n"
                        ."• Mã Ticket: #{$ticket->code}\n"
                        ."• Tiêu đề: {$ticket->title}\n"
                        ."• Phân loại: {$ticket->category_label}\n"
                        ."• Mức độ ưu tiên: {$ticket->priority_label}\n"
                        ."• Người phân công: {$actor->name}\n\n"
                        .'🔗 Xem và giải quyết ticket tại: '.route('tickets.show', $ticket->id)."\n\n"
                        ."---\n"
                        .'MEnglish Education System Notification Engine';

                    Mail::raw($emailBody, function ($mail) use ($newAssignee, $emailSubject) {
                        $mail->to($newAssignee->email)->subject($emailSubject);
                    });
                } catch (\Throwable $e) {
                    Log::warning("Không thể gửi email phân công ticket #{$ticket->code}: ".$e->getMessage());
                }
            }
        }
    }

    /**
     * Đánh dấu đã đọc
     */
    public function markAsRead(int $id): bool
    {
        $notif = AdminNotification::find($id);
        if ($notif) {
            return $notif->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }

        return false;
    }

    /**
     * Đánh dấu tất cả đã đọc theo user
     */
    public function markAllAsRead(?User $user = null): int
    {
        if (! $user) {
            $user = auth()->user();
        }
        if (! $user) {
            return 0;
        }

        $query = AdminNotification::where('is_read', false);

        if ($user->hasRole('admin') || $user->hasRole('manager')) {
            $query->where(function ($q) use ($user) {
                $q->whereNull('user_id')->orWhere('user_id', $user->id);
            });
        } else {
            $query->where('user_id', $user->id);
        }

        return $query->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
    }

    /**
     * Bắn email cảnh báo vận hành tự động (Lead trễ, Giao dịch mới, Trễ hẹn nợ, Nộp bài...)
     * tới danh sách email cấu hình trong Quản trị hệ thống.
     */
    public function sendOperationalAlertEmail(
        string $eventKey,
        string $subject,
        string $content,
        array $params = []
    ): bool {
        if (! SystemSetting::isTicketEventEnabled($eventKey)) {
            return false;
        }

        $recipients = SystemSetting::getTicketEmails();
        if (empty($recipients)) {
            return false;
        }

        try {
            $ticketObj = (object) [
                'code' => $params['code'] ?? strtoupper(substr($eventKey, 0, 4)),
                'title' => $params['title'] ?? $subject,
                'status' => $params['status'] ?? 'info',
                'status_label' => $params['status_label'] ?? 'Thông báo',
                'priority' => $params['priority'] ?? 'medium',
                'priority_label' => $params['priority_label'] ?? 'Thông thường',
                'category_label' => $params['category_label'] ?? 'Vận hành',
            ];

            $htmlBody = view('emails.ticket-notification', [
                'ticket' => $ticketObj,
                'type' => $params['type'] ?? 'alert',
                'subjectTitle' => $subject,
                'notificationTypeLabel' => $params['notification_type_label'] ?? 'Thông báo tự động',
                'priorityLabel' => $ticketObj->priority_label,
                'content' => $content,
                'senderName' => $params['sender_name'] ?? 'Hệ thống MEnglish',
                'actionUrl' => $params['action_url'] ?? url('/'),
                'actionText' => $params['action_text'] ?? 'Xem chi tiết',
            ])->render();

            foreach ($recipients as $recipientEmail) {
                Mail::html($htmlBody, function ($mail) use ($recipientEmail, $subject) {
                    $mail->to($recipientEmail)->subject($subject);
                });
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning("Không thể gửi email cảnh báo vận hành [{$eventKey}]: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Bắn email thông báo khi có giao dịch thanh toán / phiếu thu học phí mới
     */
    public function notifyTransactionReceipt(TuitionReceipt $receipt): void
    {
        if (! SystemSetting::isTicketEventEnabled('transaction')) {
            return;
        }

        $receipt->loadMissing(['tuition.student', 'tuition.classModel', 'creator']);
        $student = $receipt->tuition?->student;
        $studentName = $student?->name ?? 'Học viên';
        $studentPhone = $student?->phone ?? '---';
        $amountFormatted = number_format((float) $receipt->amount, 0, ',', '.').' VNĐ';
        $methodLabel = match ($receipt->payment_method) {
            'transfer' => 'Chuyển khoản / VietQR',
            'cash' => 'Tiền mặt',
            'pos' => 'Quẹt thẻ POS',
            'sepay' => 'Cổng SePay Tự động',
            default => $receipt->payment_method ?? 'Khác'
        };

        $subject = "[Tài chính] Giao dịch mới #{$receipt->receipt_number}: {$studentName} ({$amountFormatted})";
        $content = "Hệ thống ghi nhận giao dịch thanh toán học phí thành công:\n\n"
            ."• Số tiền: {$amountFormatted}\n"
            ."• Mã phiếu thu: {$receipt->receipt_number}\n"
            .'• Mã HĐĐT: '.($receipt->invoice_number ?? 'Chưa xuất')."\n"
            ."• Học viên: {$studentName} ({$studentPhone})\n"
            ."• Phương thức: {$methodLabel}\n"
            .'• Thời gian: '.($receipt->payment_date ? Carbon::parse($receipt->payment_date)->format('d/m/Y') : now()->format('d/m/Y'))."\n"
            .'• Ghi chú: '.($receipt->notes ?? 'Thanh toán học phí');

        $this->sendOperationalAlertEmail(
            'transaction',
            $subject,
            $content,
            [
                'code' => $receipt->receipt_number,
                'title' => "[Tài chính] Giao dịch mới: {$studentName}",
                'status' => 'approved',
                'status_label' => 'Đã duyệt',
                'priority' => 'medium',
                'priority_label' => 'Kế toán',
                'category_label' => 'Tài chính & Thu chi',
                'sender_name' => $receipt->creator?->name ?? 'Hệ thống Kế toán',
                'action_url' => route('tuition.history'),
                'action_text' => "Xem Phiếu thu #{$receipt->receipt_number}",
            ]
        );
    }

    /**
     * Xác định mốc nhắc nợ hiện tại của một hợp đồng học phí theo ngày đến hạn:
     * chưa tới hạn -> T-3, đúng ngày -> T0, quá hạn -> T+3.
     */
    public static function debtMilestoneFor(StudentTuition $tuition): ?string
    {
        if (! $tuition->due_date || (float) $tuition->debt_amount <= 0) {
            return null;
        }

        $diff = Carbon::parse($tuition->due_date)->startOfDay()->diffInDays(now()->startOfDay(), false);

        return match (true) {
            $diff < 0 => 'T-3',
            $diff === 0 => 'T0',
            default => 'T+3',
        };
    }

    /**
     * Engine nhắc nợ theo cấu hình DebtReminderRule (mốc T-3 / T0 / T+3):
     * - Rule phải tồn tại và đang bật thì mới gửi.
     * - Nội dung dùng template đã cấu hình với placeholder {ten_hoc_vien} {ma_hoc_vien}
     *   {so_dien_thoai} {lop_hoc} {so_tien} {han_dong}.
     * - Gửi thông báo vào Cổng PH/HS cho học viên + email vận hành nội bộ.
     * Idempotent trong ngày theo record_code DEBTREMIND-{mốc}-{tuition_id}-{Y-m-d}.
     */
    public function notifyDebtReminderByMilestone(StudentTuition $tuition, ?string $milestone = null): array
    {
        $milestone ??= static::debtMilestoneFor($tuition);

        if ($milestone === null) {
            return ['sent' => false, 'milestone' => null, 'reason' => 'Hợp đồng chưa có hạn đóng hoặc đã hết nợ.'];
        }

        $rule = DebtReminderRule::where('milestone_key', $milestone)->first();

        // Chưa cấu hình rule nào -> dùng template mặc định để hệ thống không im lặng;
        // chỉ khi admin chủ động tắt rule (is_enabled = false) mới chặn gửi.
        $rule ??= new DebtReminderRule([
            'milestone_key' => $milestone,
            'title' => match ($milestone) {
                'T-3' => 'Nhắc trước hạn 3 ngày',
                'T0' => 'Nhắc đúng ngày đến hạn',
                default => 'Cảnh báo quá hạn',
            },
            'template_content' => 'Học viên {ten_hoc_vien} (mã {ma_hoc_vien}, SĐT {so_dien_thoai}) lớp {lop_hoc} còn nợ {so_tien}, hạn đóng {han_dong}. Vui lòng liên hệ trung tâm để hoàn tất học phí.',
            'is_enabled' => true,
        ]);

        if (! $rule->is_enabled) {
            return ['sent' => false, 'milestone' => $milestone, 'reason' => "Mốc nhắc {$milestone} chưa được bật trong cấu hình."];
        }

        $student = $tuition->student;
        if (! $student) {
            return ['sent' => false, 'milestone' => $milestone, 'reason' => 'Không tìm thấy hồ sơ học viên.'];
        }

        $replacements = [
            '{ten_hoc_vien}' => $student->name,
            '{ma_hoc_vien}' => $student->code ?? '—',
            '{so_dien_thoai}' => $student->phone ?? '—',
            '{lop_hoc}' => $tuition->classModel?->name ?? 'Chưa phân lớp',
            '{so_tien}' => number_format((float) $tuition->debt_amount, 0, ',', '.').' VNĐ',
            '{han_dong}' => Carbon::parse($tuition->due_date)->format('d/m/Y'),
            '{moc_nhac}' => $rule->title,
        ];
        $content = strtr($rule->template_content, $replacements);

        $record = AcademicRecord::firstOrCreate(
            [
                'screen_key' => '04_Cong_Phu_Huynh_Hoc_Sinh/05_danh_sach_thong_bao',
                'record_code' => 'DEBTREMIND-'.$milestone.'-'.$tuition->id.'-'.now()->toDateString(),
            ],
            [
                'module' => 'student_portal',
                'title' => $rule->title.': '.$student->name,
                'status' => 'active',
                'data' => [
                    'type' => 'debt_reminder',
                    'title' => $rule->title,
                    'content' => $content,
                    'unread' => true,
                    'icon' => 'payments',
                    'bg_color' => 'bg-rose-100',
                    'text_color' => 'text-rose-600',
                    'student_id' => $student->id,
                    'created_at' => now()->toDateTimeString(),
                ],
                'user_id' => $student->user_id,
            ]
        );

        if ($record->wasRecentlyCreated) {
            $this->sendOperationalAlertEmail(
                'overdue_debt',
                "[Nhắc nợ {$milestone}] {$student->name} — ".number_format((float) $tuition->debt_amount, 0, ',', '.').' VNĐ',
                $content."\n\n(Đã đồng thời gửi thông báo vào Cổng PH/HS của học viên.)",
                [
                    'code' => 'DEBT-'.$milestone.'-'.$tuition->id,
                    'title' => "[Nhắc nợ {$milestone}] {$student->name}",
                    'status' => 'overdue',
                    'status_label' => 'Nhắc nợ '.$milestone,
                    'priority' => 'high',
                    'priority_label' => 'Nhắc nợ',
                    'category_label' => 'Tài chính & Công nợ',
                    'sender_name' => 'Bộ phận Thu hồi Công nợ',
                    'action_url' => route('tuition.overdue'),
                    'action_text' => 'Xem Danh sách Quá hạn',
                ]
            );
        }

        return ['sent' => true, 'milestone' => $milestone, 'reason' => 'Đã gửi theo mốc '.$rule->title.'.'];
    }

    /**
     * Bắn email cảnh báo khi có phiếu thu / học viên trễ hạn đóng học phí
     */
    public function notifyOverdueDebtReminder(StudentTuition $tuition): void
    {
        if (! SystemSetting::isTicketEventEnabled('overdue_debt')) {
            return;
        }

        $student = $tuition->student;
        $studentName = $student?->name ?? 'Học viên';
        $studentPhone = $student?->phone ?? '---';
        $debtFormatted = number_format((float) $tuition->debt_amount, 0, ',', '.').' VNĐ';
        $dueDate = $tuition->due_date ? Carbon::parse($tuition->due_date)->format('d/m/Y') : 'Chưa định ngày';
        $className = $tuition->classModel?->name ?? 'Chưa phân lớp';

        $subject = "[Nhắc nợ] Cảnh báo trễ hạn: Học viên {$studentName} ({$debtFormatted})";
        $content = "Hệ thống cảnh báo khoản học phí quá hạn cần đôn đốc thanh toán:\n\n"
            ."• Học viên: {$studentName} (SĐT: {$studentPhone})\n"
            ."• Lớp học: {$className}\n"
            ."• Số tiền nợ: {$debtFormatted}\n"
            ."• Hạn đóng: {$dueDate}\n"
            .'• Trạng thái: Đã gửi lệnh thông báo nhắc nợ tự động tới học viên.';

        $this->sendOperationalAlertEmail(
            'overdue_debt',
            $subject,
            $content,
            [
                'code' => 'DEBT-'.$tuition->id,
                'title' => "[Nhắc nợ] Trễ hẹn học phí: {$studentName}",
                'status' => 'overdue',
                'status_label' => 'Quá hạn',
                'priority' => 'high',
                'priority_label' => 'Nhắc nợ',
                'category_label' => 'Tài chính & Công nợ',
                'sender_name' => 'Bộ phận Thu hồi Công nợ',
                'action_url' => route('tuition.overdue'),
                'action_text' => 'Xem Danh sách Quá hạn',
            ]
        );
    }

    /**
     * Bắn email thông báo khi học viên nộp bài thi / bài tập trên hệ thống
     */
    public function notifyHomeworkOrTestSubmission(PlacementTestSubmission $submission): void
    {
        if (! SystemSetting::isTicketEventEnabled('homework')) {
            return;
        }

        $submission->loadMissing('test');
        $candidateName = $submission->candidate_name ?? 'Học viên';
        $candidatePhone = $submission->candidate_phone ?? '---';
        $testTitle = $submission->test?->title ?? 'Bài kiểm tra đầu vào';
        $scoreLabel = $submission->overall_score !== null
            ? "{$submission->overall_score} Band (".($submission->cefr_level ?? 'Chưa xác định').')'
            : 'Chờ Học vụ chấm';
        $course = $submission->recommended_course ?? 'Đang tư vấn';

        $subject = "[Học vụ] Học viên nộp bài: {$candidateName} - {$testTitle}";
        $content = "Học viên vừa hoàn thành và nộp bài trực tuyến trên hệ thống Portal:\n\n"
            ."• Học viên: {$candidateName} (SĐT: {$candidatePhone})\n"
            ."• Bài thi / Đề kiểm tra: {$testTitle}\n"
            ."• Điểm đánh giá: {$scoreLabel}\n"
            ."• Khóa học đề xuất: {$course}\n"
            .'• Thời gian nộp: '.now()->format('H:i d/m/Y');

        $this->sendOperationalAlertEmail(
            'homework',
            $subject,
            $content,
            [
                'code' => 'TEST-'.$submission->id,
                'title' => "[Học vụ] Nộp bài thi: {$candidateName}",
                'status' => 'completed',
                'status_label' => $scoreLabel,
                'priority' => 'medium',
                'priority_label' => 'Học vụ',
                'category_label' => 'Học vụ & Đào tạo',
                'sender_name' => 'Cổng Portal Khảo thí',
                'action_url' => route('placement-tests.results.show', $submission->id),
                'action_text' => 'Xem Báo cáo Điểm số',
            ]
        );
    }
}
