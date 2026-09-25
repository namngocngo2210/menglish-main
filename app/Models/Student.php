<?php

namespace App\Models;

use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use AuditsChanges, HasFactory, SoftDeletes;

    protected $table = 'students';

    /**
     * 6 trạng thái hồ sơ học viên (BA chốt 2026-09-25). Hồ sơ chỉ tồn tại sau khi chốt,
     * nên không có "Học thử"; "Chuyển lớp" không phải trạng thái; không có Blacklist.
     *
     * @var array<string, string> key => nhãn tiếng Việt
     */
    public const STATUSES = [
        'waiting_start' => 'Chờ khai giảng',
        'studying' => 'Đang học',
        'deferred' => 'Bảo lưu',
        'summer_break' => 'Nghỉ hè',
        'completed' => 'Hoàn thành khóa học',
        'dropped' => 'Thôi học',
    ];

    /** Trạng thái khởi tạo khi chốt (có hoặc chưa có lớp). */
    public const INITIAL_STATUS = 'waiting_start';

    /** Trạng thái Thôi học: giữ hồ sơ + lịch sử nhưng rời khỏi danh sách lớp đang học. */
    public const STATUS_DROPPED = 'dropped';

    /** Trạng thái xếp lớp (class_enrollments.status) còn chiếm chỗ trong lớp. */
    public const ACTIVE_ENROLLMENT_STATUSES = ['pending', 'completed'];

    /** Trạng thái xếp lớp khi học viên thôi học (không tính sĩ số, giữ lịch sử). */
    public const ENROLLMENT_DROPPED = 'dropped';

    /** Vai trò chỉ thấy học viên của chi nhánh mình (BA chốt Q7). Admin thấy tất cả. */
    public const BRANCH_SCOPED_ROLES = ['manager', 'academic_staff', 'academic_lead', 'accountant'];

    protected $fillable = [
        'code',
        'user_id',
        'name',
        'phone',
        'parent_name',
        'parent_phone',
        'email',
        'dob',
        'gender',
        'address',
        'school',
        'branch_id',
        'current_class_id',
        'target',
        'entrance_score',
        'midterm_score',
        'final_score',
        'attended_lessons',
        'total_lessons',
        'homework_rate',
        'status',
        'notes',
    ];

    protected $casts = [
        'dob' => 'date',
        'attended_lessons' => 'integer',
        'total_lessons' => 'integer',
        'homework_rate' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // Thôi học: bỏ khỏi lớp đang học (current_class_id) và đóng các lượt xếp lớp còn hiệu lực,
        // nhưng giữ nguyên hồ sơ, điểm danh, học phí để tra cứu lịch sử.
        static::updating(function (Student $student) {
            if ($student->isDirty('status') && $student->status === self::STATUS_DROPPED) {
                $student->current_class_id = null;
            }
        });

        static::updated(function (Student $student) {
            if ($student->wasChanged('status') && $student->status === self::STATUS_DROPPED) {
                $student->enrollments()
                    ->whereIn('status', self::ACTIVE_ENROLLMENT_STATUSES)
                    ->update(['status' => self::ENROLLMENT_DROPPED]);
            }
        });
    }

    /**
     * Học viên người dùng được xem (BA chốt Q7):
     * - Admin: toàn bộ.
     * - Quản lý cơ sở / Học vụ (và vai trò văn phòng khác trong BRANCH_SCOPED_ROLES): học viên
     *   thuộc chi nhánh của mình (users.branch_id + user_branches); học viên chưa gán chi nhánh
     *   thì xét theo chi nhánh của lớp đang học.
     * - Giáo viên / trợ giảng / vai trò khác: chỉ học viên thuộc các lớp mình phụ trách.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('admin')) {
            return $query;
        }

        if ($user->hasAnyRole(self::BRANCH_SCOPED_ROLES)) {
            $branchIds = self::branchIdsFor($user);
            if ($branchIds === []) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where(function (Builder $q) use ($branchIds) {
                $q->whereIn('branch_id', $branchIds)
                    ->orWhere(fn (Builder $q) => $q->whereNull('branch_id')
                        ->whereHas('currentClass', fn (Builder $c) => $c->whereIn('branch_id', $branchIds)));
            });
        }

        $classIds = ClassModel::query()->visibleTo($user)->pluck('id')->all();
        if ($classIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->inClasses($classIds);
    }

    /**
     * Học viên thuộc (một trong) các lớp: lớp đang học hoặc lượt xếp lớp còn hiệu lực.
     *
     * @param  array<int>|int  $classIds
     */
    public function scopeInClasses(Builder $query, array|int $classIds): Builder
    {
        $classIds = (array) $classIds;

        return $query->where(function (Builder $q) use ($classIds) {
            $q->whereIn('current_class_id', $classIds)
                ->orWhereHas('enrollments', fn (Builder $e) => $e->whereIn('class_id', $classIds)
                    ->whereIn('status', self::ACTIVE_ENROLLMENT_STATUSES));
        });
    }

    /**
     * Chi nhánh người dùng được truy cập: chi nhánh chính + chi nhánh được cấp thêm.
     *
     * @return array<int>
     */
    public static function branchIdsFor(User $user): array
    {
        return $user->branches()->pluck('branches.id')
            ->push($user->branch_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Id các lớp học viên đang theo học: lớp chính + các lớp liên kết còn hiệu lực.
     *
     * @return array<int>
     */
    public function activeClassIds(): array
    {
        return $this->enrollments()
            ->whereIn('status', self::ACTIVE_ENROLLMENT_STATUSES)
            ->pluck('class_id')
            ->push($this->current_class_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(StudentAttendance::class, 'student_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currentClass(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'current_class_id');
    }

    public function tuition(): HasOne
    {
        return $this->hasOne(StudentTuition::class, 'student_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(ClassEnrollment::class, 'student_id');
    }

    public function getAttendanceRateAttribute(): string
    {
        if ($this->total_lessons == 0) {
            return '100%';
        }
        $pct = round(($this->attended_lessons / $this->total_lessons) * 100, 1);

        return "{$this->attended_lessons}/{$this->total_lessons} ({$pct}%)";
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? (string) $this->status;
    }

    /** Màu <x-ui.badge> theo trạng thái (dùng cho chip trạng thái và bảng danh sách). */
    public const STATUS_COLORS = [
        'waiting_start' => 'info',
        'studying' => 'success',
        'deferred' => 'warning',
        'summer_break' => 'secondary',
        'completed' => 'primary',
        'dropped' => 'error',
    ];

    public function getStatusColorAttribute(): string
    {
        return self::STATUS_COLORS[$this->status] ?? 'neutral';
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            'waiting_start' => 'bg-sky-50 text-sky-700 border-sky-200',
            'studying' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'deferred' => 'bg-amber-50 text-amber-700 border-amber-200',
            'summer_break' => 'bg-cyan-50 text-cyan-700 border-cyan-200',
            'completed' => 'bg-blue-50 text-blue-700 border-blue-200',
            'dropped' => 'bg-rose-50 text-rose-700 border-rose-200',
            default => 'bg-gray-50 text-gray-700 border-gray-200',
        };
    }

    /**
     * SĐT phụ huynh để gửi kết quả (Zalo ZNS): SĐT phụ huynh trên hồ sơ học viên → SĐT phụ huynh của khách CRM
     * đã chốt ra học viên này (khách mới nhất) → không có (null). Không dùng SĐT của chính học viên.
     */
    public function parentContactPhone(): ?string
    {
        $phone = trim((string) $this->parent_phone);
        if ($phone === '') {
            $phone = trim((string) CrmCustomer::where('converted_student_id', $this->id)
                ->whereNotNull('parent_phone')->where('parent_phone', '!=', '')
                ->latest('id')->value('parent_phone'));
        }

        return $phone !== '' ? $phone : null;
    }
}
