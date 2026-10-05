<?php

namespace App\Models;

use App\Services\NotificationService;
use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminNotification extends Model
{
    use HasFactory;

    protected $table = 'admin_notifications';

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'message',
        'data',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    /**
     * Thông báo user được xem: thông báo cá nhân + thông báo chung (user_id NULL) nếu có quyền xem thông báo hệ thống.
     * Thông báo chung gắn với một khách CRM (data.customer_id, vd. lead tồn đọng) chỉ hiện khi khách nằm trong phạm vi
     * dữ liệu CRM của user — tránh link "Xử lý ngay" dẫn tới trang 404 của khách chi nhánh khác.
     */
    public function scopeForRecipient(Builder $query, User $user): Builder
    {
        if (! NotificationService::seesSystemNotifications($user)) {
            return $query->where('user_id', $user->id);
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhere(function (Builder $system) use ($user) {
                    $system->whereNull('user_id');
                    if (! DataScope::isAll($user, 'lead')) {
                        $system->where(fn (Builder $c) => $c
                            ->whereNull('data->customer_id')
                            ->orWhereIn('data->customer_id', CrmCustomer::query()->visibleTo($user)->select('id')));
                    }
                });
        });
    }

    /**
     * Thông báo cá nhân cho một người dùng (null → bỏ qua). Có $link thì gắn vào data.link cùng $data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function notifyUser(?int $userId, string $type, string $title, string $message, ?string $link = null, array $data = []): ?self
    {
        if (! $userId) {
            return null;
        }

        return static::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $link ? array_merge($data, ['link' => $link]) : ($data ?: null),
            'is_read' => false,
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getIconAttribute(): string
    {
        return match ($this->type) {
            'stale_lead_24h', 'stale_lead_care' => 'person_alert',
            'receipt_pending' => 'receipt_long',
            'receipt_approved' => 'verified',
            'receipt_rejected' => 'assignment_return',
            'overdue_report' => 'running_with_errors',
            'test_today' => 'event',
            'urgent_ticket', 'ticket_new' => 'report_problem',
            'ticket_assigned' => 'assignment_ind',
            'ticket_message' => 'chat',
            'ticket_status' => 'published_with_changes',
            'contract_expiring' => 'contract',
            'task_assigned' => 'assignment',
            'class_report_pending' => 'fact_check',
            'trial_booked' => 'person_search',
            'trial_feedback' => 'rate_review',
            'class_assigned' => 'co_present',
            'care_overdue' => 'volunteer_activism',
            'cash_deposit_overdue' => 'savings',
            'refund_deadline' => 'hourglass_bottom',
            'monthly_report_due' => 'edit_calendar',
            'big_test_paper_due' => 'quiz',
            'adjustment_pending', 'adjustment_sla' => 'tune',
            'material_order_new' => 'inventory_2',
            'material_order_overdue' => 'running_with_errors',
            'penalty_created' => 'gavel',
            'sla_breach' => 'timer_off',
            'penalty_fined', 'penalty_due_reminder' => 'payments',
            'payroll_calendar' => 'event_upcoming',
            'appointment_confirm_manual' => 'event_available',
            'payment_confirm_manual' => 'paid',
            default => 'notifications',
        };
    }

    public function getBadgeColorAttribute(): string
    {
        return match ($this->type) {
            'stale_lead_24h', 'stale_lead_care' => 'bg-error/10 text-error border-error/30',
            'receipt_pending' => 'bg-warning/10 text-warning border-warning/30',
            'receipt_approved' => 'bg-tertiary/10 text-tertiary border-tertiary/30',
            'receipt_rejected', 'overdue_report' => 'bg-error/10 text-error border-error/30',
            'test_today' => 'bg-secondary/10 text-secondary border-secondary/30',
            'urgent_ticket', 'ticket_new' => 'bg-error/10 text-error border-error/30',
            'ticket_assigned' => 'bg-accent-container text-accent border-accent/30',
            'ticket_message' => 'bg-secondary/10 text-secondary border-secondary/30',
            'ticket_status' => 'bg-tertiary/10 text-tertiary border-tertiary/30',
            'contract_expiring' => 'bg-warning/10 text-warning border-warning/30',
            'task_assigned' => 'bg-tertiary/10 text-tertiary border-tertiary/30',
            'class_report_pending' => 'bg-primary-container/10 text-primary border-primary-container/30',
            'trial_booked', 'trial_feedback' => 'bg-secondary/10 text-secondary border-secondary/30',
            'class_assigned' => 'bg-primary-container/10 text-primary border-primary-container/30',
            'care_overdue' => 'bg-error/10 text-error border-error/30',
            'cash_deposit_overdue', 'refund_deadline' => 'bg-error/10 text-error border-error/30',
            'monthly_report_due' => 'bg-warning/10 text-warning border-warning/30',
            'cash_deposit_overdue', 'refund_deadline', 'big_test_paper_due', 'adjustment_sla' => 'bg-error/10 text-error border-error/30',
            'adjustment_pending' => 'bg-warning/10 text-warning border-warning/30',
            'material_order_new' => 'bg-primary-container/10 text-primary border-primary-container/30',
            'material_order_overdue' => 'bg-error/10 text-error border-error/30',
            'penalty_created', 'penalty_fined', 'sla_breach' => 'bg-error/10 text-error border-error/30',
            'penalty_due_reminder', 'payroll_calendar' => 'bg-warning/10 text-warning border-warning/30',
            'appointment_confirm_manual', 'payment_confirm_manual' => 'bg-warning/10 text-warning border-warning/30',
            default => 'bg-surface-container-low text-on-surface-variant border-surface-container-highest',
        };
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            'stale_lead_24h' => 'Lead sót >24h',
            'stale_lead_care' => 'Khách bị bỏ quên',
            'receipt_pending' => 'Phiếu thu chờ duyệt',
            'receipt_rejected' => 'Phiếu thu bị trả về',
            'overdue_report' => 'Báo cáo nợ quá hạn',
            'test_today' => 'Lịch test trong ngày',
            'urgent_ticket' => 'Ticket khẩn cấp',
            'ticket_new' => 'Ticket hỗ trợ mới',
            'ticket_assigned' => 'Phân công Ticket',
            'ticket_message' => 'Phản hồi Ticket',
            'ticket_status' => 'Cập nhật Ticket',
            'contract_expiring' => 'Hợp đồng sắp hết hạn',
            'task_assigned' => 'Được giao việc',
            'class_report_pending' => 'Báo cáo trực lớp chờ duyệt',
            'trial_booked' => 'Khách học thử',
            'trial_feedback' => 'Nhận xét học thử',
            'class_assigned' => 'Xếp dạy lớp',
            'care_overdue' => 'Quá hạn chăm sóc',
            'cash_deposit_overdue' => 'Tiền mặt chưa nộp về TK',
            'refund_deadline' => 'Hạn xử lý hoàn phí',
            'monthly_report_due' => 'Nhắc báo cáo giảng dạy tháng',
            'big_test_paper_due' => 'Nhắc duyệt đề Big Test',
            'adjustment_pending' => 'Yêu cầu giãn tiến độ',
            'adjustment_sla' => 'Giãn tiến độ quá hạn duyệt',
            'material_order_new' => 'Order học liệu mới',
            'material_order_overdue' => 'Order học liệu quá hạn',
            'penalty_created' => 'Biên bản vi phạm',
            'sla_breach' => 'Quá hạn SLA',
            'penalty_fined' => 'Quyết phạt',
            'penalty_due_reminder' => 'Nhắc hạn nộp phạt',
            'payroll_calendar' => 'Lịch chốt lương',
            'appointment_confirm_manual' => 'Xác nhận lịch hẹn qua Zalo',
            'payment_confirm_manual' => 'Báo đã nhận học phí qua Zalo',
            default => 'Thông báo hệ thống',
        };
    }
}
