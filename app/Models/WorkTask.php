<?php

namespace App\Models;

use App\Services\FirstMonthCareService;
use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkTask extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'creator_id',
        'assignee_id',
        'branch_id',
        'class_id',
        'student_id',
        'care_milestone',
        'lesson_session',
        'time_slot_category',
        'task_type',
        'frequency',
        'due_date',
        'due_time',
        'status',
        'completion_note',
        'completion_proof_image',
        'blocked_reason',
        'rejection_reason',
        'confirmed_by',
        'confirmed_at',
        'completed_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'confirmed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Việc chăm sóc tháng đầu hoàn thành → đánh dấu checklist chăm sóc bên CRM.
        static::updated(function (WorkTask $task) {
            if ($task->care_milestone && $task->wasChanged('status') && $task->status === 'completed') {
                app(FirstMonthCareService::class)->syncCompletedTask($task);
            }

            // Việc "Lặp đi lặp lại": hoàn thành một lượt → tự tạo lượt kế tiếp theo tần suất.
            if ($task->task_type === 'recurring' && $task->wasChanged('status') && $task->status === 'completed') {
                $task->spawnNextOccurrence();
            }
        });
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function classModel()
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function classReport()
    {
        return $this->hasOne(ClassReport::class, 'task_id');
    }

    /** Ca trực của trợ giảng (Phase 4 — "trợ giảng 3 ca"). */
    public const TIME_SLOTS = [
        'before' => 'Trước giờ học',
        'during' => 'Trong giờ học',
        'after' => 'Sau giờ học',
    ];

    /** Trạng thái còn phải làm (sẽ thành "Quá hạn" khi qua hạn). */
    public const OPEN_STATUSES = ['new', 'in_progress'];

    /**
     * Tạo lượt kế tiếp của việc lặp (hạn + 1 ngày / 1 tuần / 1 tháng), cùng người giao / người nhận.
     * Không tạo trùng nếu lượt kế tiếp (cùng tiêu đề, người nhận, hạn) đã có.
     */
    public function spawnNextOccurrence(): ?self
    {
        if (! $this->due_date) {
            return null;
        }
        $next = match ($this->frequency) {
            'daily' => $this->due_date->copy()->addDay(),
            'monthly' => $this->due_date->copy()->addMonthNoOverflow(),
            default => $this->due_date->copy()->addWeek(),
        };

        $exists = self::query()
            ->where('title', $this->title)
            ->where('assignee_id', $this->assignee_id)
            ->where('task_type', 'recurring')
            ->whereDate('due_date', $next->toDateString())
            ->exists();
        if ($exists) {
            return null;
        }

        return self::create([
            'title' => $this->title,
            'description' => $this->description,
            'creator_id' => $this->creator_id,
            'assignee_id' => $this->assignee_id,
            'branch_id' => $this->branch_id,
            'class_id' => $this->class_id,
            'time_slot_category' => $this->time_slot_category,
            'task_type' => 'recurring',
            'frequency' => $this->frequency ?: 'weekly',
            'due_date' => $next->toDateString(),
            'due_time' => $this->due_time,
            'status' => 'new',
        ]);
    }

    /** Thời điểm hết hạn = ngày hạn + giờ hạn (không có giờ → cuối ngày). */
    public function dueAt(): ?\Carbon\CarbonInterface
    {
        if (! $this->due_date) {
            return null;
        }
        $time = $this->due_time ? substr((string) $this->due_time, 0, 5) : '23:59';
        if (! preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time = '23:59';
        }

        return $this->due_date->copy()->setTimeFromTimeString($time);
    }

    /** Số giờ trễ hạn (làm tròn lên), 0 nếu chưa quá hạn. */
    public function lateHours(?\Carbon\CarbonInterface $now = null): int
    {
        $dueAt = $this->dueAt();
        $now ??= now();
        if (! $dueAt || $now->lessThanOrEqualTo($dueAt)) {
            return 0;
        }

        return (int) ceil($dueAt->diffInMinutes($now) / 60);
    }

    // Helper accessor for status badge label & class
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'new' => 'Mới',
            'in_progress' => 'Đang thực hiện',
            'pending_confirmation' => 'Chờ xác nhận',
            'blocked' => 'Bị chặn',
            'completed' => 'Hoàn thành',
            'overdue' => 'Quá hạn',
            'canceled' => 'Đã hủy',
            default => $this->status,
        };
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return match ($this->status) {
            'new' => 'bg-gray-100 text-gray-700 border-gray-200',
            'in_progress' => 'bg-amber-50 text-amber-700 border-amber-200',
            'pending_confirmation' => 'bg-orange-50 text-orange-700 border-orange-200',
            'blocked' => 'bg-red-50 text-red-700 border-red-200',
            'completed' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'overdue' => 'bg-rose-50 text-rose-700 border-rose-200',
            'canceled' => 'bg-slate-100 text-slate-500 border-slate-200',
            default => 'bg-gray-100 text-gray-700 border-gray-200',
        };
    }

    public function getTaskTypeLabelAttribute(): string
    {
        return match ($this->task_type) {
            'one_time' => 'Phát sinh',
            'recurring' => 'Lặp đi lặp lại',
            default => $this->task_type,
        };
    }

    public function getTimeSlotCategoryLabelAttribute(): string
    {
        return match ($this->time_slot_category) {
            'before' => 'Trước giờ học',
            'during' => 'Trong giờ học',
            'after' => 'Sau giờ học',
            default => 'Trong giờ học',
        };
    }

    /**
     * Phạm vi Công việc (DataScope `work_task`): "Của mình" = việc mình giao / mình làm; "Chi nhánh" = việc của chi
     * nhánh mình hoặc người làm thuộc chi nhánh mình (kèm việc của mình).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return DataScope::apply(
            $query, $user, 'work_task',
            fn ($q) => $q->where('creator_id', $user->id)->orWhere('assignee_id', $user->id),
            fn ($q, array $branchIds) => $q->whereIn('branch_id', $branchIds)
                ->orWhereHas('assignee', fn ($a) => $a->whereIn('branch_id', $branchIds)),
            branchIncludesOwn: true,
        );
    }

    /**
     * Việc thường chờ $user xác nhận hoàn thành (màn "Xác nhận hoàn thành việc", hộp "Việc cần duyệt"):
     * người có quyền duyệt thấy trong phạm vi, người khác chỉ việc mình giao; không ai duyệt việc của chính mình.
     * Việc "Trực lớp" có báo cáo chờ xác nhận đi theo luật Q8 (ClassReport), không lặp ở đây.
     */
    public function scopeAwaitingConfirmationBy(Builder $query, User $user): Builder
    {
        $query->where('status', 'pending_confirmation')
            ->whereDoesntHave('classReport', fn ($q) => $q->where('status', ClassReport::STATUS_PENDING))
            ->where(fn ($q) => $q->whereNull('assignee_id')->orWhere('assignee_id', '!=', $user->id));

        return $user->can('work_task.approve') ? $query->visibleTo($user) : $query->where('creator_id', $user->id);
    }
}
