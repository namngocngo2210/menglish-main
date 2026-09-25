<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    use HasFactory;

    protected $table = 'support_tickets';

    protected $fillable = [
        'code',
        'title',
        'category',
        'priority',
        'status',
        'creator_id',
        'assignee_id',
        'description',
        'attachment_path',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'support_ticket_id')->latest();
    }

    public function getAttachmentListAttribute(): array
    {
        if (empty($this->attachment_path)) {
            return [];
        }
        $decoded = json_decode($this->attachment_path, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        return [$this->attachment_path];
    }

    public static function generateCode(): string
    {
        $latest = static::whereRaw('LENGTH(code) = 4 AND code >= "1000" AND code <= "9999"')
            ->orderBy('code', 'desc')
            ->value('code');

        $nextNumber = $latest && is_numeric($latest) ? ((int) $latest + 1) : 1001;

        while (static::where('code', (string) $nextNumber)->exists()) {
            $nextNumber++;
        }

        return (string) $nextNumber;
    }

    public function getCategoryLabelAttribute(): string
    {
        return match ($this->category) {
            'technical_issue' => 'Lỗi Hệ Thống / IT',
            'curriculum' => 'Giáo Trình / Học Vụ',
            'tuition' => 'Học Phí / Hóa Đơn',
            'customer_complaint' => 'Khiếu Nại Học Viên',
            default => 'Yêu Cầu Hỗ Trợ Khác',
        };
    }

    public function getPriorityBadgeAttribute(): string
    {
        return match ($this->priority) {
            'urgent' => 'bg-rose-50 text-rose-700 border-rose-200 font-bold',
            'high' => 'bg-orange-50 text-orange-700 border-orange-200 font-bold',
            'medium' => 'bg-blue-50 text-blue-700 border-blue-200 font-medium',
            default => 'bg-gray-50 text-gray-600 border-gray-200',
        };
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            'open' => 'bg-amber-50 text-amber-700 border-amber-200',
            'in_progress' => 'bg-blue-50 text-blue-700 border-blue-200',
            'resolved' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'closed' => 'bg-gray-50 text-gray-600 border-gray-200',
            default => 'bg-gray-50 text-gray-600 border-gray-200',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'open' => 'Mới tiếp nhận',
            'in_progress' => 'Đang xử lý',
            'resolved' => 'Đã giải quyết',
            'closed' => 'Đã đóng ticket',
            default => $this->status,
        };
    }
}
