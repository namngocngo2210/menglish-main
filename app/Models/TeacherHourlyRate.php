<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Đơn giá giờ dạy riêng của một giáo viên, hiệu lực từ effective_from cho tới
 * khi có dòng mới hơn. Không sửa/xoá dòng cũ để giữ lịch sử.
 */
class TeacherHourlyRate extends Model
{
    protected $table = 'teacher_hourly_rates';

    protected $fillable = [
        'user_id',
        'hourly_rate',
        'effective_from',
        'note',
        'created_by',
    ];

    protected $casts = [
        'hourly_rate' => 'decimal:2',
        'effective_from' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Đơn giá riêng hiệu lực tại một ngày (null = GV chưa có đơn giá riêng tại ngày đó). */
    public static function rateFor(int $userId, CarbonInterface|string $date): ?float
    {
        $rate = static::query()
            ->where('user_id', $userId)
            ->whereDate('effective_from', '<=', Carbon::parse($date)->toDateString())
            ->orderByDesc('effective_from')
            ->value('hourly_rate');

        return $rate === null ? null : (float) $rate;
    }
}
