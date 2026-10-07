<?php

namespace App\Models;

use App\Services\SessionScheduleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Khung giờ ca dạy (Cài đặt → Tổ chức & Đào tạo → Khung giờ ca dạy). Mỗi khung có giờ chuẩn và (tuỳ chọn) giờ lệch
 * trên TKB. Quy tắc áp khi xếp lịch: App\Services\TeachingShifts\TeachingShiftRules.
 */
class TeachingShift extends Model
{
    use SoftDeletes;

    public const WEEKDAY = 'weekday';

    public const SATURDAY = 'saturday';

    public const SUNDAY = 'sunday';

    public const DAY_TYPES = [
        self::WEEKDAY => 'Thứ 2 – Thứ 6',
        self::SATURDAY => 'Thứ 7',
        self::SUNDAY => 'Chủ nhật',
    ];

    /** Mỗi ca dạy 90 phút (file khung giờ chấm công). */
    public const DURATION_MINUTES = 90;

    protected $fillable = [
        'day_type',
        'name',
        'start_time',
        'end_time',
        'alt_start_time',
        'alt_end_time',
        'note',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByRaw("CASE day_type WHEN 'weekday' THEN 1 WHEN 'saturday' THEN 2 ELSE 3 END")
            ->orderBy('start_time')->orderBy('sort_order');
    }

    /** Loại ngày của tên thứ ("Thứ 2" … "Chủ nhật") hoặc số thứ ISO (1–7). */
    public static function dayTypeOf(string|int $day): string
    {
        $iso = is_int($day) ? $day : (SessionScheduleService::DAY_MAP[$day] ?? 1);

        return match ($iso) {
            6 => self::SATURDAY,
            7 => self::SUNDAY,
            default => self::WEEKDAY,
        };
    }

    /** "HH:MM" của cột giờ (MySQL TIME "18:00:00", SQLite lưu nguyên chuỗi). */
    public static function hm(?string $time): ?string
    {
        return $time ? substr($time, 0, 5) : null;
    }

    public function standardRange(): string
    {
        return self::hm($this->start_time).'–'.self::hm($this->end_time);
    }

    public function altRange(): ?string
    {
        return $this->alt_start_time && $this->alt_end_time ? self::hm($this->alt_start_time).'–'.self::hm($this->alt_end_time) : null;
    }

    /**
     * Các lựa chọn giờ của khung: giờ chuẩn và giờ lệch (nếu có).
     *
     * @return list<array{variant: string, start: string, end: string}>
     */
    public function variants(): array
    {
        $variants = [['variant' => 'standard', 'start' => self::hm($this->start_time), 'end' => self::hm($this->end_time)]];
        if ($this->altRange()) {
            $variants[] = ['variant' => 'alt', 'start' => self::hm($this->alt_start_time), 'end' => self::hm($this->alt_end_time)];
        }

        return $variants;
    }

    /** @return Collection<int, self> */
    public static function activeFor(string $dayType): Collection
    {
        return static::query()->active()->where('day_type', $dayType)->ordered()->get();
    }
}
