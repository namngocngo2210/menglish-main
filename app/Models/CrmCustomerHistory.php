<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmCustomerHistory extends Model
{
    use HasFactory;

    protected $table = 'crm_customer_histories';

    protected $fillable = [
        'customer_id',
        'user_id',
        'type',
        'content',
        'from_stage',
        'to_stage',
        'reason',
        'changes',
    ];

    /** Nhóm lọc nhật ký trên hồ sơ khách: type => nhãn. */
    public const FILTER_TYPES = [
        'call' => 'Gọi điện',
        'message' => 'Nhắn tin',
        'meet' => 'Gặp trực tiếp',
        'test' => 'Test đầu vào',
        'trial' => 'Học thử',
        'stage_change' => 'Chuyển giai đoạn',
        'update' => 'Sửa thông tin',
        'assign' => 'Phân công',
        'care' => 'Chăm sóc tháng đầu',
        'note' => 'Ghi chú',
        'system' => 'Hệ thống',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CrmCustomer::class, 'customer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getTypeIconAttribute(): string
    {
        return match ($this->type) {
            'call' => 'call',
            'message' => 'chat',
            'meet' => 'groups',
            'test' => 'quiz',
            'stage_change' => 'sync_alt',
            'trial' => 'school',
            'update' => 'edit_note',
            'assign' => 'assignment_ind',
            'care' => 'volunteer_activism',
            default => 'notes',
        };
    }
}
