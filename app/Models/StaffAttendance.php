<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chấm công hằng ngày của một nhân sự (1 dòng / người / ngày). Giờ vào / ra lấy theo máy chủ lúc bấm chấm công,
 * kèm ảnh khuôn mặt (lưu riêng tư, chỉ người chấm và người có quyền xem chấm công mới mở được), toạ độ GPS và
 * khoảng cách tới cơ sở. Dòng do đơn "Bổ sung công" được duyệt tạo / sửa có source = request (không có ảnh / GPS).
 * Đi muộn tính theo giờ vào của ngày đó (expected_start: buổi dạy đầu tiên với GV / TA, giờ làm việc cơ sở với người khác).
 */
class StaffAttendance extends Model
{
    public const SOURCE_MOBILE = 'mobile';

    public const SOURCE_REQUEST = 'request';

    /** Thư mục ảnh trên disk local (storage/app/private, không public). */
    public const PHOTO_DIR = 'staff-attendance';

    protected $fillable = [
        'user_id',
        'branch_id',
        'work_date',
        'source',
        'expected_start',
        'expected_end',
        'check_in_at',
        'check_in_photo',
        'check_in_lat',
        'check_in_lng',
        'check_in_distance',
        'check_in_accuracy',
        'check_out_at',
        'check_out_photo',
        'check_out_lat',
        'check_out_lng',
        'check_out_distance',
        'check_out_accuracy',
        'late_minutes',
        'early_minutes',
        'late_excused',
        'penalty_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'check_in_lat' => 'float',
            'check_in_lng' => 'float',
            'check_out_lat' => 'float',
            'check_out_lng' => 'float',
            'check_in_distance' => 'integer',
            'check_out_distance' => 'integer',
            'check_in_accuracy' => 'integer',
            'check_out_accuracy' => 'integer',
            'late_minutes' => 'integer',
            'early_minutes' => 'integer',
            'late_excused' => 'boolean',
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

    public function penalty(): BelongsTo
    {
        return $this->belongsTo(Penalty::class);
    }

    /** Đi muộn tính lỗi (quá ngưỡng cho phép và không có đơn xin đi muộn được duyệt). */
    public function isLate(): bool
    {
        return $this->late_minutes > 0 && ! $this->late_excused;
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->check_in_at === null => 'Chưa vào',
            $this->late_minutes > 0 && $this->late_excused => 'Muộn có phép',
            $this->late_minutes > 0 => 'Đi muộn '.$this->late_minutes.' phút',
            $this->check_out_at === null => 'Chưa ra',
            $this->early_minutes > 0 => 'Về sớm '.$this->early_minutes.' phút',
            default => 'Đúng giờ',
        };
    }

    public function statusTone(): string
    {
        return match (true) {
            $this->check_in_at === null => 'neutral',
            $this->isLate() => 'error',
            $this->late_minutes > 0, $this->early_minutes > 0, $this->check_out_at === null => 'warning',
            default => 'success',
        };
    }

    /** Số phút làm việc thực tế (vào → ra); null khi thiếu giờ ra. */
    public function workedMinutes(): ?int
    {
        return $this->check_in_at && $this->check_out_at
            ? max(0, (int) $this->check_in_at->diffInMinutes($this->check_out_at))
            : null;
    }

    public static function forDay(int $userId, CarbonInterface|string $date): ?self
    {
        return static::query()->where('user_id', $userId)
            ->whereDate('work_date', $date instanceof CarbonInterface ? $date->toDateString() : $date)
            ->first();
    }
}
