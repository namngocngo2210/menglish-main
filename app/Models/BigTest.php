<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BigTest extends Model
{
    use HasFactory;

    /** Vai trò học thuật được thao tác trên mọi lớp (không bị giới hạn theo phân công). */
    public const ACADEMIC_ROLES = ['admin', 'manager', 'academic_staff', 'academic_lead'];

    protected $table = 'big_tests';

    protected $fillable = [
        'code',
        'title',
        'class_id',
        'test_type',
        'scheduled_at',
        'room',
        'proctor_id',
        'passcode',
        'content_url',
        'is_distributed',
        'status',
        'approved_by',
        'approved_at',
        'distributed_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'is_distributed' => 'boolean',
        'approved_at' => 'datetime',
        'distributed_at' => 'datetime',
    ];

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function proctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proctor_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(BigTestResult::class, 'big_test_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Lọc các đợt thi mà user được xem: học thuật xem tất cả, giáo viên/trợ giảng
     * chỉ xem đợt thi của lớp mình được phân công.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasAnyRole(self::ACADEMIC_ROLES)) {
            return $query;
        }

        return $query->whereHas('classModel', fn (Builder $class) => $class->where(
            fn (Builder $q) => $q->where('teacher_id', $user->id)
                ->orWhere('foreign_teacher_id', $user->id)
                ->orWhere('assistant_id', $user->id)
        ));
    }

    public function isAccessibleBy(User $user): bool
    {
        if ($user->hasAnyRole(self::ACADEMIC_ROLES)) {
            return true;
        }

        $class = $this->classModel;

        return $class !== null && in_array((int) $user->id, array_map('intval', array_filter([
            $class->teacher_id, $class->foreign_teacher_id, $class->assistant_id,
        ])), true);
    }

    /**
     * Passcode đề thi chỉ hiển thị cho người có quyền duyệt đề, hoặc giáo viên
     * đứng lớp (GV chính / GV nước ngoài) sau khi đề đã được phân phối.
     */
    public function passcodeVisibleTo(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->can('syllabus.approve_adjustment')) {
            return true;
        }

        $class = $this->classModel;

        return $this->is_distributed && $class !== null && in_array((int) $user->id, array_map('intval', array_filter([
            $class->teacher_id, $class->foreign_teacher_id,
        ])), true);
    }
}
