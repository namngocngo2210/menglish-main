<?php

namespace App\Models;

use App\Models\Concerns\AuditsChanges;
use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phòng học của một chi nhánh. Lớp gắn phòng qua classes.room_id; tên phòng còn được chép vào classes.room và
 * class_sessions.room (kiểm tra trùng phòng theo chi nhánh + tên, lịch dạy) nên tên là duy nhất trong một chi nhánh.
 */
class Room extends Model
{
    use AuditsChanges, SoftDeletes;

    protected $fillable = ['branch_id', 'room_type_id', 'name', 'capacity', 'description'];

    protected function casts(): array
    {
        return ['capacity' => 'integer'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id')->withTrashed();
    }

    public function classes(): HasMany
    {
        return $this->hasMany(ClassModel::class, 'room_id');
    }

    /** Lớp còn dùng phòng: chưa kết thúc / chưa hủy. */
    public function openClasses(): HasMany
    {
        return $this->classes()->whereNotIn('status', ClassModel::CLOSED_STATUSES);
    }

    /** Phòng trong phạm vi dữ liệu "room.scope_*" của người dùng (chi nhánh của tôi / mọi chi nhánh). */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return DataScope::apply($query, $user, 'room', null, fn (Builder $q, array $ids) => $q->whereIn('branch_id', $ids));
    }

    /** Nhãn trong ô chọn phòng: "Phòng 202 • Mẫu giáo • 12 chỗ". */
    public function optionLabel(): string
    {
        return collect([
            $this->name,
            $this->type?->name,
            $this->capacity ? "{$this->capacity} chỗ" : null,
        ])->filter()->implode(' • ');
    }
}
