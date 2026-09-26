<?php

namespace App\Models;

use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BigTest extends Model
{
    use HasFactory;

    /**
     * Hạn trả kết quả cho phụ huynh = ngày thi + N ngày (mockup "Hạn trả kết quả: Còn N ngày").
     * Tạm đặt 7 ngày — chờ BA chốt con số chính thức.
     */
    public const RESULT_DEADLINE_DAYS = 7;

    protected $table = 'big_tests';

    protected $fillable = [
        'code',
        'title',
        'class_id',
        'syllabus_stage_id',
        'test_type',
        'scheduled_at',
        'room',
        'proctor_id',
        'passcode',
        'content_url',
        'speaking_url',
        'is_distributed',
        'status',
        'approved_by',
        'approved_at',
        'distributed_at',
        'teacher_reminded_at',
        'results_completed_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'is_distributed' => 'boolean',
        'approved_at' => 'datetime',
        'distributed_at' => 'datetime',
        'teacher_reminded_at' => 'datetime',
        'results_completed_at' => 'datetime',
    ];

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    /** Chặng giáo trình mà đợt thi này là Big Test cuối chặng (Q4). */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(SyllabusStage::class, 'syllabus_stage_id');
    }

    public function proctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proctor_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(BigTestResult::class, 'big_test_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(BigTestOrder::class, 'big_test_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Lọc các đợt thi user được xem theo phạm vi "big_test.scope_*" (DataScope): Toàn hệ thống (mặc định Admin,
     * Quản lý cơ sở, Học vụ, Học thuật) → mọi đợt thi; Chi nhánh → lớp mình + lớp thuộc chi nhánh mình;
     * Của tôi (giáo viên / trợ giảng) → đợt thi của lớp mình được phân công.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return DataScope::apply(
            $query, $user, 'big_test',
            fn (Builder $q) => $q->whereHas('classModel', fn (Builder $class) => self::assignedTo($class, $user)),
            fn (Builder $q, array $branchIds) => $q->whereHas('classModel', fn (Builder $class) => $class->whereIn('branch_id', $branchIds)),
            branchIncludesOwn: true,
        );
    }

    /** Lớp user được phân công (GV chính / GVNN / trợ giảng). */
    public static function assignedTo(Builder $class, User $user): Builder
    {
        return $class->where(fn (Builder $q) => $q->where('teacher_id', $user->id)
            ->orWhere('foreign_teacher_id', $user->id)
            ->orWhere('assistant_id', $user->id));
    }

    /** Hạn trả kết quả cho phụ huynh (ngày thi + RESULT_DEADLINE_DAYS). */
    public function resultsDueAt(): ?\Illuminate\Support\Carbon
    {
        return $this->scheduled_at?->copy()->startOfDay()->addDays(self::RESULT_DEADLINE_DAYS);
    }

    /** Số ngày còn lại tới hạn trả kết quả (âm = quá hạn); NULL khi chưa có ngày thi. */
    public function resultsDaysLeft(): ?int
    {
        $due = $this->resultsDueAt();

        return $due ? (int) today()->diffInDays($due, false) : null;
    }

    public function isAccessibleBy(User $user): bool
    {
        $level = DataScope::level($user, 'big_test');
        if ($level === DataScope::ALL) {
            return true;
        }

        $class = $this->classModel;
        if ($class === null) {
            return false;
        }
        if ($level === DataScope::BRANCH && $class->branch_id && in_array((int) $class->branch_id, $user->branchIds(), true)) {
            return true;
        }

        return in_array((int) $user->id, array_map('intval', array_filter([
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

        if ($user->can('big_test.approve')) {
            return true;
        }

        $class = $this->classModel;

        return $this->is_distributed && $class !== null && in_array((int) $user->id, array_map('intval', array_filter([
            $class->teacher_id, $class->foreign_teacher_id,
        ])), true);
    }

    /** Link đề đầy đủ chỉ người duyệt đề (Học thuật / Admin) xem. */
    public function contentLinkVisibleTo(?User $user): bool
    {
        return $user !== null && $user->can('big_test.approve');
    }

    /**
     * Link phần Speaking: GV chính / GVNN của lớp xem được sau khi đề đã phân phối (ghi chú nghiệp vụ mockup
     * "GV chỉ được quyền xem phần Speaking của đề sau khi phân phối"); người duyệt đề luôn xem được.
     */
    public function speakingLinkVisibleTo(?User $user): bool
    {
        if ($user === null || ! $this->speaking_url) {
            return false;
        }
        if ($user->can('big_test.approve')) {
            return true;
        }
        $class = $this->classModel;

        return $this->is_distributed && $class !== null && in_array((int) $user->id, array_map('intval', array_filter([
            $class->teacher_id, $class->foreign_teacher_id,
        ])), true);
    }
}
