<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Đơn xin duyệt về chấm công của nhân sự (mục "Xin duyệt" trên điện thoại), duyệt ở hộp Việc cần duyệt:
 *  - correction: Bổ sung công — quên / không chấm được, khai giờ vào / ra của 1 ngày.
 *  - late_early: Xin đi muộn / về sớm 1 ngày — đi muộn ngày đó không tính lỗi (biên bản tự động đang mở bị hủy).
 *  - leave:      Xin nghỉ (từ ngày → đến ngày) — ngày nghỉ có phép, hiện trên bảng công và phiếu lương.
 */
class StaffAttendanceRequest extends Model
{
    public const TYPE_CORRECTION = 'correction';

    public const TYPE_LATE_EARLY = 'late_early';

    public const TYPE_LEAVE = 'leave';

    public const TYPES = [
        self::TYPE_CORRECTION => 'Bổ sung công',
        self::TYPE_LATE_EARLY => 'Xin đi muộn / về sớm',
        self::TYPE_LEAVE => 'Xin nghỉ',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING => 'Chờ duyệt',
        self::STATUS_APPROVED => 'Đã duyệt',
        self::STATUS_REJECTED => 'Từ chối',
        self::STATUS_CANCELLED => 'Đã rút',
    ];

    protected $fillable = [
        'user_id',
        'branch_id',
        'type',
        'date_from',
        'date_to',
        'check_in_time',
        'check_out_time',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /** Đơn có hiệu lực trùng khoảng ngày [from, to]. */
    public function scopeOverlapping(Builder $query, string $from, string $to): Builder
    {
        return $query->whereDate('date_from', '<=', $to)->whereDate('date_to', '>=', $from);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED => 'success',
            self::STATUS_REJECTED => 'error',
            self::STATUS_PENDING => 'warning',
            default => 'neutral',
        };
    }

    /** Số ngày của đơn (gồm cả 2 đầu). */
    public function days(): int
    {
        return (int) $this->date_from->diffInDays($this->date_to) + 1;
    }

    /** "dd/mm" hoặc "dd/mm → dd/mm" kèm giờ khai (đơn bổ sung công). */
    public function periodLabel(): string
    {
        $label = $this->date_from->format('d/m/Y');
        if (! $this->date_from->isSameDay($this->date_to)) {
            $label .= ' → '.$this->date_to->format('d/m/Y').' ('.$this->days().' ngày)';
        }
        if ($this->type === self::TYPE_CORRECTION) {
            $times = array_filter([
                $this->check_in_time ? 'vào '.substr((string) $this->check_in_time, 0, 5) : null,
                $this->check_out_time ? 'ra '.substr((string) $this->check_out_time, 0, 5) : null,
            ]);
            $label .= $times ? ' · '.implode(', ', $times) : '';
        }

        return $label;
    }
}
