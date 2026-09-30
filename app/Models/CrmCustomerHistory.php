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
        'result' => 'Gửi kết quả',
        'trial' => 'Học thử',
        'stage_change' => 'Chuyển giai đoạn',
        'update' => 'Sửa thông tin',
        'assign' => 'Phân công',
        'care' => 'Chăm sóc tháng đầu',
        'note' => 'Ghi chú',
        'system' => 'Hệ thống',
    ];

    /** Nhật ký cho thấy đã liên hệ khách (gọi / nhắn / gặp) — lead Mới có các nhật ký này không còn là "chưa liên hệ". */
    public const CONTACT_TYPES = ['call', 'message', 'meet'];

    /**
     * Nhật ký tính là một lần chăm sóc khách (đồng hồ SLA "chăm sóc tiếp theo trong 72h" đếm lại từ lần gần nhất):
     * liên hệ + test / gửi kết quả / học thử / chuyển giai đoạn. Sửa thông tin, phân công, ghi chú, hệ thống không tính.
     */
    public const CARE_TYPES = ['call', 'message', 'meet', 'test', 'result', 'trial', 'stage_change'];

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
            'result' => 'forward_to_inbox',
            'stage_change' => 'sync_alt',
            'trial' => 'school',
            'update' => 'edit_note',
            'assign' => 'assignment_ind',
            'care' => 'volunteer_activism',
            'lost' => 'person_off',
            'system' => 'settings',
            default => 'notes',
        };
    }
}
