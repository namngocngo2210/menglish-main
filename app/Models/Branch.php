<?php

namespace App\Models;

use App\Models\Concerns\AuditsChanges;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use AuditsChanges, HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'address',
        'phone',
        'is_active',
        'latitude',
        'longitude',
        'checkin_radius',
        'work_start_time',
        'work_end_time',
        'late_grace_minutes',
    ];

    /** Bán kính chấm công mặc định (mét) khi cơ sở chưa đặt riêng. */
    public const DEFAULT_CHECKIN_RADIUS = 100;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'checkin_radius' => 'integer',
            'late_grace_minutes' => 'integer',
        ];
    }

    /** Cơ sở đã cài toạ độ chấm công chưa (chưa cài thì nhân sự không chấm công được). */
    public function hasCheckinLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** Khoảng cách (mét, làm tròn) từ một điểm GPS tới toạ độ cơ sở; null khi cơ sở chưa cài toạ độ. */
    public function distanceTo(float $latitude, float $longitude): ?int
    {
        return $this->hasCheckinLocation()
            ? (int) round(Geo::distanceMeters($this->latitude, $this->longitude, $latitude, $longitude))
            : null;
    }

    /** Giờ vào / ra dạng "HH:MM" (cột time của MySQL trả "HH:MM:SS"). */
    public function workStart(): ?string
    {
        return $this->work_start_time ? substr((string) $this->work_start_time, 0, 5) : null;
    }

    public function workEnd(): ?string
    {
        return $this->work_end_time ? substr((string) $this->work_end_time, 0, 5) : null;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Nhân viên được cấp quyền truy cập bổ sung vào chi nhánh này.
     */
    public function accessibleByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_branches');
    }

    public function holidays(): BelongsToMany
    {
        return $this->belongsToMany(Holiday::class, 'holiday_branches');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
