<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Đơn giá riêng của một giáo viên, hiệu lực từ effective_from cho tới khi có dòng mới hơn.
 * Không sửa/xoá dòng cũ để giữ lịch sử. Số tiền ở cột hourly_rate (tên cũ), đơn vị ở rate_unit:
 * 'session' = đ/buổi (Q3 Part-time: số buổi × đơn giá buổi), 'hour' = đ/giờ (các dòng trước Q3).
 */
class TeacherHourlyRate extends Model
{
    protected $table = 'teacher_hourly_rates';

    protected $fillable = [
        'user_id',
        'hourly_rate',
        'rate_unit',
        'effective_from',
        'note',
        'created_by',
    ];

    protected $casts = [
        'hourly_rate' => 'decimal:2',
        'effective_from' => 'date',
    ];

    public const UNIT_SESSION = 'session';

    public const UNIT_HOUR = 'hour';

    public const UNITS = [self::UNIT_SESSION => 'đ/buổi', self::UNIT_HOUR => 'đ/giờ'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Phiên bản đơn giá riêng hiệu lực tại một ngày (null = GV chưa có đơn giá riêng tại ngày đó). */
    public static function effectiveFor(int $userId, CarbonInterface|string $date): ?self
    {
        return static::query()
            ->where('user_id', $userId)
            ->whereDate('effective_from', '<=', Carbon::parse($date)->toDateString())
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Đơn giá GIỜ riêng hiệu lực tại một ngày (null = không có, hoặc phiên bản hiệu lực tính theo buổi).
     */
    public static function rateFor(int $userId, CarbonInterface|string $date): ?float
    {
        $rate = static::effectiveFor($userId, $date);

        return $rate === null || $rate->isPerSession() ? null : (float) $rate->hourly_rate;
    }

    public function isPerSession(): bool
    {
        return $this->rate_unit === self::UNIT_SESSION;
    }

    public function getUnitLabelAttribute(): string
    {
        return self::UNITS[$this->rate_unit] ?? 'đ/giờ';
    }
}
