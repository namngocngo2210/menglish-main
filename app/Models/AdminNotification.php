<?php

namespace App\Models;

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
            'ticket_assigned' => 'bg-purple-50 text-purple-700 border-purple-200',
            'ticket_message' => 'bg-secondary/10 text-secondary border-secondary/30',
            'ticket_status' => 'bg-tertiary/10 text-tertiary border-tertiary/30',
            'contract_expiring' => 'bg-warning/10 text-warning border-warning/30',
            'task_assigned' => 'bg-tertiary/10 text-tertiary border-tertiary/30',
            'class_report_pending' => 'bg-primary-container/10 text-primary border-primary-container/30',
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
            default => 'Thông báo hệ thống',
        };
    }
}
