<?php

namespace App\Models;

use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassModel extends Model
{
    use AuditsChanges, HasFactory, SoftDeletes;

    protected $table = 'classes';

    protected $fillable = [
        'code',
        'name',
        'course_id',
        'program',
        'level',
        'branch_id',
        'teacher_id',
        'assistant_id',
        'foreign_teacher_id',
        'room',
        'schedule_text',
        'start_date',
        'end_date',
        'max_capacity',
        'tuition_fee',
        'notes',
        'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'max_capacity' => 'integer',
    ];

    /**
     * Lớp mà người dùng được xem: nhân sự quản lý lớp (class.create/update) thấy
     * tất cả; giáo viên / GVNN / trợ giảng chỉ thấy lớp mình phụ trách hoặc có
     * buổi dạy; vai trò khác (học viên...) không thấy lớp nào.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        // Quản lý cơ sở (không phải Admin) chỉ thấy lớp thuộc chi nhánh mình.
        $managedBranchIds = $user->managedBranchIds();
        $managesClasses = self::userManagesAll($user);

        if ($managesClasses && $managedBranchIds === null) {
            return $query;
        }

        // Phạm vi được cấp riêng qua "Phân quyền cá nhân" (class.view/update/delete theo chi nhánh/lớp).
        $grantedBranchIds = [];
        $grantedClassIds = [];
        foreach (UserPermissionOverride::SCOPE_ENFORCED['class'] as $action) {
            $grantedBranchIds = array_merge($grantedBranchIds, $user->scopedOverrideIds('class', $action, UserPermissionOverride::SCOPE_BRANCH));
            $grantedClassIds = array_merge($grantedClassIds, $user->scopedOverrideIds('class', $action, UserPermissionOverride::SCOPE_CLASS));
        }

        return $query->where(function (Builder $query) use ($user, $managesClasses, $managedBranchIds, $grantedBranchIds, $grantedClassIds) {
            $query->where('teacher_id', $user->id)
                ->orWhere('assistant_id', $user->id)
                ->orWhere('foreign_teacher_id', $user->id)
                ->orWhereHas('sessions', function (Builder $sessions) use ($user) {
                    $sessions->where(fn (Builder $q) => $q->where('teacher_id', $user->id)->orWhere('assistant_id', $user->id));
                });

            if ($managesClasses && ! empty($managedBranchIds)) {
                $query->orWhereIn('branch_id', $managedBranchIds);
            }
            if (! empty($grantedBranchIds)) {
                $query->orWhereIn('branch_id', array_values(array_unique($grantedBranchIds)));
            }
            if (! empty($grantedClassIds)) {
                $query->orWhereIn('id', array_values(array_unique($grantedClassIds)));
            }
        });
    }

    /**
     * Nhân sự quản lý lớp theo vai trò (hoặc override "Toàn hệ thống"). Override
     * theo chi nhánh/lớp KHÔNG tính ở đây (chỉ mở rộng trong phạm vi được cấp).
     */
    public static function userManagesAll(User $user): bool
    {
        return $user->hasModuleAction('class', 'create') || $user->hasModuleAction('class', 'update');
    }

    /**
     * Người dùng có được thực hiện "class.$action" trên lớp này không: lớp phải
     * nằm trong phạm vi được thấy, và quyền được cấp (theo lớp, theo chi nhánh,
     * toàn hệ thống hoặc theo vai trò).
     */
    public function userCan(User $user, string $action): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        if (! static::query()->visibleTo($user)->whereKey($this->getKey())->exists()) {
            return false;
        }

        return $user->hasModuleAction('class', $action, UserPermissionOverride::SCOPE_CLASS, (int) $this->getKey())
            || ($this->branch_id && $user->hasModuleAction('class', $action, UserPermissionOverride::SCOPE_BRANCH, (int) $this->branch_id));
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assistant_id');
    }

    public function foreignTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'foreign_teacher_id');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'current_class_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(ClassEnrollment::class, 'class_id');
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(TeacherTimesheet::class, 'class_id');
    }

    public function scheduleConfig()
    {
        return $this->hasOne(ClassScheduleConfig::class, 'class_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class, 'class_id');
    }

    public function supportSessions(): HasMany
    {
        return $this->hasMany(SupportSession::class, 'class_id');
    }

    public function syllabusAssignments(): HasMany
    {
        return $this->hasMany(SyllabusAssignment::class, 'class_id');
    }
}
