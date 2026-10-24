<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Chặng của lớp: lớp đang học chặng nào của giáo trình, GV phụ trách, mốc mở/đóng.
 *
 * `status = in_progress` là chặng đang mở. Mỗi lớp chỉ có 1 chặng đang mở: cột `open_class_id`
 * (= class_id khi đang mở, NULL khi đã đóng) có UNIQUE nên DB từ chối chặng mở thứ hai.
 * Mở/đóng chặng đi qua App\Services\SyllabusProgressionService.
 */
class SyllabusAssignment extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'in_progress';

    public const STATUS_CLOSED = 'completed';

    public const STATUS_LABELS = [
        self::STATUS_OPEN => 'Đang học',
        self::STATUS_CLOSED => 'Đã đóng',
    ];

    protected $table = 'syllabus_assignments';

    protected $fillable = [
        'user_id',
        'curriculum_id',
        'stage_id',
        'class_id',
        'assigned_chapters',
        'stage_name',
        'start_date',
        'planned_sessions',
        'deadline',
        'expected_big_test_date',
        'big_test_reminded_for',
        'progress_percent',
        'status',
        'opened_at',
        'opened_by',
        'open_reason',
        'closed_at',
        'closed_by',
        'close_reason',
        'closed_by_big_test_id',
        'curriculum_completed_at',
        'extra_sessions',
        'assigned_at',
        'confirmed_at',
        'confirmed_by',
        'confirm_reminded_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'planned_sessions' => 'integer',
        'assigned_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'confirm_reminded_at' => 'datetime',
        'deadline' => 'date',
        'expected_big_test_date' => 'date',
        'big_test_reminded_for' => 'date',
        'progress_percent' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'curriculum_completed_at' => 'datetime',
        'extra_sessions' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $assignment) {
            $assignment->open_class_id = $assignment->status === self::STATUS_OPEN && $assignment->class_id
                ? $assignment->class_id
                : null;
        });
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(SyllabusCurriculum::class, 'curriculum_id')->withTrashed();
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(SyllabusStage::class, 'stage_id');
    }

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function closingBigTest(): BelongsTo
    {
        return $this->belongsTo(BigTest::class, 'closed_by_big_test_id');
    }

    /** GV đã bấm XÁC NHẬN nhận syllabus của chặng (Bước 4 quy trình). */
    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /** Người được xác nhận: GV được giao chặng, GV chính hoặc GVNN của lớp. */
    public function canConfirm(User $user): bool
    {
        $this->loadMissing('classModel');

        return in_array($user->id, array_filter([$this->user_id, $this->classModel?->teacher_id, $this->classModel?->foreign_teacher_id]), false);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(ClassLessonProgress::class, 'syllabus_assignment_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /** Mã chặng của lớp hiển thị trên màn nhắc lịch: CH-{năm mở}-{id}. */
    public function getCodeAttribute(): string
    {
        return 'CH-'.($this->opened_at ?? $this->created_at ?? now())->format('Y').'-'.str_pad((string) $this->id, 3, '0', STR_PAD_LEFT);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }
}
