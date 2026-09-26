<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\CarbonInterface;

class TuitionRefundRequest extends Model
{
    use HasFactory;

    public const TYPE_REFUND = 'refund';

    public const TYPE_TRANSFER = 'transfer';

    /** Khất nợ: duyệt xong dời hạn đóng và tạm dừng nhắc nợ tới hạn mới. */
    public const TYPE_EXTENSION = 'extension';

    /** Bảo lưu: duyệt xong học viên sang "Bảo lưu", đóng băng số buổi còn lại & công nợ trong thời gian bảo lưu. */
    public const TYPE_DEFERRAL = 'deferral';

    public const TYPES = [
        self::TYPE_REFUND => 'Hoàn phí',
        self::TYPE_TRANSFER => 'Chuyển nhượng',
        self::TYPE_EXTENSION => 'Khất nợ',
        self::TYPE_DEFERRAL => 'Bảo lưu',
    ];

    /**
     * Quyền duyệt theo loại hồ sơ (BA 26/09/2026 — Admin cấp / thu hồi ở màn Vai trò hoặc Phân quyền cá nhân).
     * Mặc định: hoàn tiền chỉ Admin (A6 "Hoàn phí"); chuyển nhượng, khất nợ, bảo lưu: Admin, Quản lý cơ sở, Kế toán.
     */
    public const APPROVE_PERMISSIONS = [
        self::TYPE_REFUND => 'refund_transfer.approve_refund',
        self::TYPE_TRANSFER => 'refund_transfer.approve_transfer',
        self::TYPE_EXTENSION => 'refund_transfer.approve',
        self::TYPE_DEFERRAL => 'refund_transfer.approve',
    ];

    /** Quyền từ chối mọi loại hồ sơ. */
    public const REJECT_PERMISSION = 'refund_transfer.reject';

    public static function approvePermission(?string $type): string
    {
        return self::APPROVE_PERMISSIONS[$type] ?? 'refund_transfer.approve';
    }

    protected $table = 'tuition_refund_requests';

    protected $fillable = [
        'student_id',
        'type',
        'total_paid',
        'attended_lessons',
        'admin_fee',
        'refund_amount',
        'extended_due_date',
        'defer_from',
        'defer_to',
        'target_student_id',
        'reason',
        'no_transfer_reason',
        'proof_path',
        'rejection_reason',
        'requester_id',
        'approver_id',
        'status',
        'clawback_commission',
        'clawback_amount',
        'clawback_user_id',
        'approved_at',
    ];

    protected $casts = [
        'total_paid' => 'decimal:2',
        'admin_fee' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'attended_lessons' => 'integer',
        'clawback_commission' => 'boolean',
        'clawback_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'extended_due_date' => 'date',
        'defer_from' => 'date',
        'defer_to' => 'date',
    ];

    /** Ổ lưu ảnh bằng chứng hoàn tiền (riêng tư, xem qua route kiểm tra quyền). */
    public const PROOF_DISK = 'local';

    /** Số ngày xử lý tối đa (A6 "Hoàn phí": trong 1 tuần và trong cùng tháng phát sinh). */
    public const PROCESSING_DAYS = 7;

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? (string) $this->type;
    }

    /** Hồ sơ nghỉ giữa khóa (hoàn tiền / chuyển nhượng buổi dư) chịu hạn xử lý của A6; khất nợ / bảo lưu thì không. */
    public function hasProcessingDeadline(): bool
    {
        return in_array($this->type, [self::TYPE_REFUND, self::TYPE_TRANSFER], true);
    }

    /**
     * Hạn xử lý = min(ngày lập + 7 ngày, ngày cuối tháng lập) — để khớp sổ sách trong tháng.
     * Trả về cuối ngày hạn; null nếu loại hồ sơ không có hạn.
     */
    public static function deadlineFor(CarbonInterface $createdAt): CarbonInterface
    {
        $week = $createdAt->copy()->addDays(self::PROCESSING_DAYS)->endOfDay();
        $monthEnd = $createdAt->copy()->endOfMonth();

        return $week->lt($monthEnd) ? $week : $monthEnd;
    }

    public function getProcessingDeadlineAttribute(): ?CarbonInterface
    {
        if (! $this->hasProcessingDeadline() || ! $this->created_at) {
            return null;
        }

        return self::deadlineFor($this->created_at);
    }

    /**
     * Cờ "Quá hạn xử lý": còn chờ duyệt mà đã qua hạn, hoặc đã xử lý (duyệt / từ chối) sau hạn.
     * Chỉ là cờ cảnh báo — không chặn nút duyệt.
     */
    public function isProcessingOverdue(?CarbonInterface $now = null): bool
    {
        $deadline = $this->processing_deadline;
        if (! $deadline) {
            return false;
        }

        if ($this->status === 'pending') {
            return ($now ?? now())->gt($deadline);
        }

        $processedAt = $this->approved_at ?? $this->updated_at;

        return $processedAt !== null && $processedAt->gt($deadline);
    }

    /** Số ngày đã quá hạn xử lý (hồ sơ còn chờ), 0 nếu chưa quá hạn. */
    public function processingOverdueDays(?CarbonInterface $now = null): int
    {
        $deadline = $this->processing_deadline;
        if (! $deadline || $this->status !== 'pending') {
            return 0;
        }
        $now ??= now();

        return $now->gt($deadline) ? max(1, (int) $deadline->copy()->startOfDay()->diffInDays($now->copy()->startOfDay())) : 0;
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function targetStudent(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'target_student_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /** Sale bị thu hồi hoa hồng (nếu người duyệt chọn thu hồi). */
    public function clawbackUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'clawback_user_id');
    }
}
