<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Buổi học thử của khách (lead) trong một buổi học thật của lớp.
 * Học thử là hoạt động trong giai đoạn tư vấn — KHÔNG phải stage pipeline,
 * phản hồi của giáo viên được lưu gắn với lead (customer_id), không phải học viên.
 */
class CrmTrialBooking extends Model
{
    /** Mỗi khách học thử tối đa 2 lần; xếp từng buổi một — buổi 2 xếp lại như lần 1 sau khi buổi 1 kết thúc. */
    public const MAX_ACTIVE_PER_LEAD = 2;

    /** Trạng thái tính là đã dùng một lượt học thử (vắng mặt / đã hủy trả lại lượt). */
    public const COUNTED_STATUSES = ['scheduled', 'attended'];

    /** Đầu mục nhận xét giống nhận xét buổi học của học sinh chính thức (teacher/remarks). */
    public const REMARK_FIELDS = [
        'grammar' => 'Thực hành ngữ pháp',
        'attitude' => 'Tinh thần học tập',
        'result' => 'Kết quả',
    ];

    public const STATUSES = [
        'scheduled' => 'Đã hẹn',
        'attended' => 'Đã học thử',
        'no_show' => 'Vắng mặt',
        'cancelled' => 'Đã hủy',
    ];

    protected $fillable = [
        'customer_id',
        'class_id',
        'class_session_id',
        'booked_by',
        'status',
        'notes',
        'rating',
        'feedback',
        'remarks',
        'feedback_by',
        'feedback_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'remarks' => 'array',
            'feedback_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CrmCustomer::class, 'customer_id');
    }

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function bookedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by');
    }

    public function feedbackBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'feedback_by');
    }

    /** Buổi học thử đã diễn ra (hoặc đang diễn ra hôm nay) — mới được điểm danh / nhận xét. */
    public function sessionHasStarted(): bool
    {
        return $this->session?->date !== null && $this->session->date->lte(today());
    }

    /** Thời điểm buổi học thử kết thúc (ngày buổi + giờ kết thúc; thiếu giờ thì hết ngày). */
    public function sessionEndsAt(): ?Carbon
    {
        $date = $this->session?->date;
        if (! $date) {
            return null;
        }

        return $this->session->end_time ? $date->copy()->setTimeFrom($this->session->end_time) : $date->copy()->endOfDay();
    }

    /** Buổi học thử đang chờ (chưa kết thúc): khách đang "gắn" vào buổi này, chưa xếp được buổi tiếp theo. */
    public function isPending(): bool
    {
        $end = $this->sessionEndsAt();

        return $this->status === 'scheduled' && $end !== null && $end->isFuture();
    }

    /**
     * Tình trạng học thử của khách: đã dùng bao nhiêu lượt, buổi đang chờ, còn xếp được không.
     *
     * @param  Collection<int, self>  $bookings  toàn bộ buổi học thử của khách (đã nạp session)
     * @return array{used: int, remaining: int, pending: ?self, exhausted: bool}
     */
    public static function stateFor(Collection $bookings): array
    {
        $used = $bookings->whereIn('status', self::COUNTED_STATUSES)->count();

        return [
            'used' => $used,
            'remaining' => max(0, self::MAX_ACTIVE_PER_LEAD - $used),
            'pending' => $bookings->first(fn (self $booking) => $booking->isPending()),
            'exhausted' => $used >= self::MAX_ACTIVE_PER_LEAD,
        ];
    }

    /** Nhận xét dạng một dòng (dùng cho nhật ký tuyển sinh): "Thực hành ngữ pháp: Khá · Tinh thần: ...". */
    public function remarksSummary(): string
    {
        return collect(self::REMARK_FIELDS)
            ->filter(fn (string $label, string $key) => filled($this->remarks[$key] ?? null))
            ->map(fn (string $label, string $key) => $label.': '.$this->remarks[$key])
            ->implode(' · ');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
