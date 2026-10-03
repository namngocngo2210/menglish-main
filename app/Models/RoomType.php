<?php

namespace App\Models;

use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Danh mục loại phòng dùng chung toàn hệ thống (Phòng Mẫu giáo, Phòng Lab máy tính…).
 * Loại đang có phòng dùng thì không xóa được, chỉ "Ngừng dùng" (không chọn được cho phòng mới).
 */
class RoomType extends Model
{
    use AuditsChanges, SoftDeletes;

    protected $fillable = ['name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Tên kèm "(ngừng dùng)" khi loại đã ngừng dùng. */
    public function displayName(): string
    {
        return $this->is_active ? $this->name : "{$this->name} (ngừng dùng)";
    }
}
