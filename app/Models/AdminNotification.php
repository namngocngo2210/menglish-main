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
            'stale_lead_24h' => 'person_alert',
            'receipt_pending' => 'receipt_long',
            'receipt_approved' => 'verified',
            'receipt_rejected' => 'assignment_return',
            'overdue_report' => 'running_with_errors',
            'test_today' => 'event',
            'urgent_ticket', 'ticket_new' => 'report_problem',
            'ticket_assigned' => 'assignment_ind',
            'ticket_message' => 'chat',
            'ticket_status' => 'published_with_changes',
            default => 'notifications',
        };
    }

    public function getBadgeColorAttribute(): string
    {
        return match ($this->type) {
            'stale_lead_24h' => 'bg-rose-50 text-rose-700 border-rose-200',
            'receipt_pending' => 'bg-amber-50 text-amber-700 border-amber-200',
            'receipt_approved' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'receipt_rejected', 'overdue_report' => 'bg-rose-50 text-rose-700 border-rose-200',
            'test_today' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
            'urgent_ticket', 'ticket_new' => 'bg-red-50 text-red-700 border-red-200',
            'ticket_assigned' => 'bg-purple-50 text-purple-700 border-purple-200',
            'ticket_message' => 'bg-blue-50 text-blue-700 border-blue-200',
            'ticket_status' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            default => 'bg-gray-50 text-gray-700 border-gray-200',
        };
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            'stale_lead_24h' => 'Lead sót >24h',
            'receipt_pending' => 'Phiếu thu chờ duyệt',
            'receipt_rejected' => 'Phiếu thu bị trả về',
            'overdue_report' => 'Báo cáo nợ quá hạn',
            'test_today' => 'Lịch test trong ngày',
            'urgent_ticket' => 'Ticket khẩn cấp',
            'ticket_new' => 'Ticket hỗ trợ mới',
            'ticket_assigned' => 'Phân công Ticket',
            'ticket_message' => 'Phản hồi Ticket',
            'ticket_status' => 'Cập nhật Ticket',
            default => 'Thông báo hệ thống',
        };
    }
}
