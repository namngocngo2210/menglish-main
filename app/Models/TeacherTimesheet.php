<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherTimesheet extends Model
{
    use HasFactory;

    protected $table = 'teacher_timesheets';

    protected $fillable = [
        'user_id',
        'class_id',
        'class_session_id',
        'teaching_date',
        'scheduled_time',
        'checkin_time',
        'checkout_time',
        'hours',
        'hourly_rate',
        'type',
        'source',
        'status',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'adjusted_at',
        'adjusted_by',
        'adjustment_reason',
        'notes',
    ];

    protected $casts = [
        'teaching_date' => 'date',
        'hours' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'adjusted_at' => 'datetime',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function adjuster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }

    /**
     * Tình trạng giờ vào / ra theo mockup "Chi tiết chấm công GV":
     * adjusted = Chỉnh tay (Học vụ sửa giờ), missing_in = thiếu giờ vào,
     * missing_out = thiếu giờ ra (chỉ với ca chấm tay — check-in tính giờ theo lịch buổi học), full = Đầy đủ.
     */
    public function getPunchStateAttribute(): string
    {
        if ($this->adjusted_at !== null) {
            return 'adjusted';
        }
        if (blank($this->checkin_time)) {
            return 'missing_in';
        }
        if (blank($this->checkout_time) && ! in_array($this->source, [self::SOURCE_CHECKIN, self::SOURCE_SCHEDULE], true)) {
            return 'missing_out';
        }

        return 'full';
    }

    public function getPunchStateLabelAttribute(): string
    {
        return match ($this->punch_state) {
            'adjusted' => 'Chỉnh tay',
            'missing_in' => 'Thiếu vào',
            'missing_out' => 'Thiếu ra',
            default => 'Đầy đủ',
        };
    }

    /** Giờ ra hiển thị: ca check-in không có giờ ra → giờ kết thúc theo lịch buổi học. */
    public function getDisplayCheckoutAttribute(): ?string
    {
        if (filled($this->checkout_time)) {
            return $this->checkout_time;
        }
        if (in_array($this->source, [self::SOURCE_CHECKIN, self::SOURCE_SCHEDULE], true) && $this->scheduled_time && str_contains($this->scheduled_time, '-')) {
            return trim(explode('-', $this->scheduled_time)[1]);
        }

        return null;
    }

    /** Đơn giá mặc định khi cả ca dạy lẫn nhân sự đều chưa cấu hình. */
    public const DEFAULT_HOURLY_RATE = 250000;

    public const SOURCE_CHECKIN = 'checkin';

    public const SOURCE_MANUAL = 'manual';

    /** Học vụ / Admin "Xác nhận" buổi học trên lịch (mockup Chấm công theo lịch) — giờ theo lịch buổi học. */
    public const SOURCE_SCHEDULE = 'schedule';

    /**
     * Đơn giá áp dụng khi tính lương:
     * timesheet.hourly_rate → đơn giá riêng của GV hiệu lực tại ngày dạy (teacher_hourly_rates)
     * → users.hourly_rate → mặc định.
     */
    public function effectiveHourlyRate(?User $user = null): float
    {
        if ((float) $this->hourly_rate > 0) {
            return (float) $this->hourly_rate;
        }

        // Phiên bản hiệu lực tính theo buổi → rateFor trả null, rơi về đơn giá giờ của hồ sơ / mặc định.
        $personalRate = TeacherHourlyRate::rateFor((int) $this->user_id, $this->teaching_date ?? now());
        if ($personalRate !== null && $personalRate > 0) {
            return $personalRate;
        }

        $user ??= $this->teacher;

        return (float) ($user?->hourly_rate) > 0 ? (float) $user->hourly_rate : self::DEFAULT_HOURLY_RATE;
    }

    /**
     * Tiền công của ca dạy cho GV Part-time (Q3: mỗi ca chấm công hợp lệ = 1 buổi):
     * 1. Ca có đơn giá nhập tay (hourly_rate) → số giờ × đơn giá đó (điều chỉnh riêng ca, như trước).
     * 2. Đơn giá riêng hiệu lực tại ngày dạy tính theo BUỔI → 1 buổi × đơn giá buổi.
     * 3. Còn lại (đơn giá riêng theo giờ, users.hourly_rate, mặc định) → số giờ × đơn giá giờ (dữ liệu trước Q3).
     *
     * @return array{amount: float, unit: string, rate: float}
     */
    public function sessionPay(?User $user = null): array
    {
        if ((float) $this->hourly_rate <= 0) {
            $personal = TeacherHourlyRate::effectiveFor((int) $this->user_id, $this->teaching_date ?? now());
            if ($personal && $personal->isPerSession() && (float) $personal->hourly_rate > 0) {
                return ['amount' => (float) $personal->hourly_rate, 'unit' => TeacherHourlyRate::UNIT_SESSION, 'rate' => (float) $personal->hourly_rate];
            }
        }

        $rate = $this->effectiveHourlyRate($user);

        return ['amount' => round((float) $this->hours * $rate, 2), 'unit' => TeacherHourlyRate::UNIT_HOUR, 'rate' => $rate];
    }

    /**
     * Buổi học thật (ClassSession) ứng với một nhân sự dạy lớp vào một ngày.
     * Ưu tiên buổi phân công đúng người (GV/TA); nếu không có thì chọn buổi
     * trùng khung giờ vào/ra; cuối cùng là buổi duy nhất trong ngày.
     */
    public static function matchSession(int $userId, int $classId, string $date, ?string $timeIn = null, ?string $timeOut = null): ?ClassSession
    {
        $sessions = ClassSession::where('class_id', $classId)->whereDate('date', $date)->orderBy('start_time')->get();
        if ($sessions->isEmpty()) {
            return null;
        }

        $assigned = $sessions->first(fn (ClassSession $s) => in_array($userId, [(int) $s->teacher_id, (int) $s->assistant_id], true));
        if ($assigned) {
            return $assigned;
        }

        if ($timeIn && $timeOut) {
            $overlap = $sessions->first(fn (ClassSession $s) => $s->start_time && $s->end_time
                && $s->start_time->format('H:i') < $timeOut && $s->end_time->format('H:i') > $timeIn);
            if ($overlap) {
                return $overlap;
            }
        }

        return $sessions->count() === 1 ? $sessions->first() : null;
    }

    /**
     * Bản ghi chấm công đang có hiệu lực (chưa bị từ chối) trùng với ca định ghi:
     * cùng người + cùng buổi học, hoặc cùng người + cùng lớp + cùng ngày khi
     * bản ghi cũ/mới không gắn buổi học. Dùng để chặn tính công 2 lần.
     */
    public static function findDuplicate(int $userId, int $classId, string $date, ?int $sessionId, ?int $ignoreId = null): ?self
    {
        return static::query()
            ->where('user_id', $userId)
            ->where('status', '!=', 'invalid')
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where(function ($query) use ($classId, $date, $sessionId) {
                $sameDay = fn ($q) => $q->where('class_id', $classId)->whereDate('teaching_date', $date);
                if ($sessionId) {
                    $query->where('class_session_id', $sessionId)
                        ->orWhere(fn ($q) => $sameDay($q->whereNull('class_session_id')));
                } else {
                    $query->where($sameDay);
                }
            })
            ->first();
    }

    public function getSourceLabelAttribute(): string
    {
        return match ($this->source) {
            self::SOURCE_MANUAL => 'Chấm tay',
            self::SOURCE_CHECKIN => 'Check-in',
            self::SOURCE_SCHEDULE => 'Xác nhận theo lịch',
            default => '—',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'valid' => 'Hợp lệ',
            'invalid' => 'Từ chối',
            'pending_review' => 'Chờ duyệt',
            default => (string) $this->status,
        };
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            'regular' => 'Ca dạy chính khóa',
            'sub' => 'Dạy thay (Sub)',
            '1on1' => 'Kèm phụ đạo 1-1',
            'grading' => 'Chấm bài thi Test',
            'workshop' => 'Workshop / Sự kiện',
            default => $this->type,
        };
    }
}
