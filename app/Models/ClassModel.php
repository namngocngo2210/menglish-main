<?php

namespace App\Models;

use App\Models\Concerns\AuditsChanges;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassModel extends Model
{
    use AuditsChanges, HasFactory, SoftDeletes;

    protected $table = 'classes';

    /** Sĩ số đã nạp sẵn bởi loadRosterCounts() (không phải cột DB). */
    public ?int $rosterCountCache = null;

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
                    $sessions->forStaff($user->id);
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

    /** Trạng thái học viên còn giữ chỗ trong lớp (tính vào sĩ số). */
    public const SEAT_HOLDING_STUDENT_STATUSES = ['waiting_start', 'studying', 'summer_break'];

    /** Trạng thái bàn giao xếp lớp còn hiệu lực (khớp CRM: pending/completed). */
    public const ACTIVE_ENROLLMENT_STATUSES = ['pending', 'completed'];

    /**
     * Số học viên đang giữ chỗ: hợp của bàn giao xếp lớp còn hiệu lực và học viên có
     * current_class_id là lớp này (hai nguồn có thể lệch nhau — xem audit A4 #7), chỉ tính
     * học viên chưa thôi học/hoàn thành/bảo lưu.
     */
    public function occupiedSeats(): int
    {
        return $this->roster()->count();
    }

    /**
     * Danh sách lớp thật (audit A4 #7): hợp của học viên có current_class_id là lớp này và học
     * viên có lượt xếp lớp còn hiệu lực ("Liên kết lớp khác"), chỉ học viên còn giữ chỗ (bỏ Thôi
     * học / Hoàn thành / Bảo lưu). Dùng cho điểm danh, hồ sơ lớp, sĩ số, nhập điểm.
     */
    public function roster(): Builder
    {
        return static::rosterQuery($this->getKey());
    }

    /**
     * @param  array<int>|int  $classIds
     */
    public static function rosterQuery(array|int $classIds): Builder
    {
        return Student::query()
            ->inClasses($classIds)
            ->whereIn('status', self::SEAT_HOLDING_STUDENT_STATUSES);
    }

    /** Học viên trong danh sách lớp (đã sắp theo tên). */
    public function rosterStudents(): Collection
    {
        return $this->roster()->orderBy('name')->get();
    }

    public function hasOnRoster(int $studentId): bool
    {
        return $this->roster()->whereKey($studentId)->exists();
    }

    /**
     * Sĩ số (theo danh sách lớp thật) cho nhiều lớp bằng 2 truy vấn; gán vào thuộc tính
     * roster_count của từng lớp để view dùng $class->roster_count.
     *
     * @param  iterable<ClassModel>  $classes
     */
    public static function loadRosterCounts(iterable $classes): void
    {
        $list = collect($classes instanceof Paginator ? $classes->items() : $classes);
        $ids = $list->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return;
        }

        $pairs = Student::query()
            ->whereIn('status', self::SEAT_HOLDING_STUDENT_STATUSES)
            ->whereIn('current_class_id', $ids)
            ->get(['id', 'current_class_id'])
            ->map(fn ($s) => [(int) $s->current_class_id, (int) $s->id]);
        $pairs = $pairs->merge(
            ClassEnrollment::query()
                ->whereIn('class_id', $ids)
                ->whereIn('status', self::ACTIVE_ENROLLMENT_STATUSES)
                ->whereHas('student', fn (Builder $q) => $q->whereIn('status', self::SEAT_HOLDING_STUDENT_STATUSES))
                ->get(['class_id', 'student_id'])
                ->map(fn ($e) => [(int) $e->class_id, (int) $e->student_id])
        );
        $counts = $pairs->groupBy(0)->map(fn ($rows) => $rows->pluck(1)->unique()->count());

        foreach ($list as $class) {
            $class->rosterCountCache = (int) ($counts[(int) $class->id] ?? 0);
        }
    }

    /** Sĩ số theo danh sách lớp thật (dùng giá trị đã nạp sẵn nếu có). */
    public function getRosterCountAttribute(): int
    {
        return $this->rosterCountCache ??= $this->occupiedSeats();
    }

    /**
     * Số chỗ còn trống; null khi lớp không giới hạn sĩ số (max_capacity trống hoặc 0).
     */
    public function seatsLeft(): ?int
    {
        if ((int) $this->max_capacity <= 0) {
            return null;
        }

        return max(0, (int) $this->max_capacity - $this->occupiedSeats());
    }

    public function isFull(): bool
    {
        return $this->seatsLeft() === 0;
    }

    /** Còn đủ chỗ cho $count học viên nữa không (dùng khi xếp lớp thủ công). */
    public function hasSeatsFor(int $count = 1): bool
    {
        $left = $this->seatsLeft();

        return $left === null || $left >= $count;
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
