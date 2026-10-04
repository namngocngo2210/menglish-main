<?php

namespace App\Models;

use App\Services\DocumentCodeGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dự án học thuật (soạn sách, xây chương trình…). Luồng: Lên kế hoạch (họp thống nhất, thêm mốc) → Chốt tiến độ
 * (Đang thực hiện; từ đây mốc trễ hạn bị lập biên bản theo SLA academic.milestone_late) → Hoàn thành / Tạm dừng / Hủy.
 * Tiến độ dự án = trung bình % các mốc (mỗi mốc = khối lượng đã xong / khối lượng cần làm).
 */
class AcademicProject extends Model
{
    use SoftDeletes;

    public const TYPES = [
        'book' => 'Soạn sách / giáo trình',
        'curriculum' => 'Xây dựng chương trình',
        'other' => 'Dự án khác',
    ];

    public const STATUSES = [
        'planning' => 'Lên kế hoạch',
        'active' => 'Đang thực hiện',
        'paused' => 'Tạm dừng',
        'completed' => 'Hoàn thành',
        'cancelled' => 'Đã hủy',
    ];

    public const STATUS_COLORS = [
        'planning' => 'secondary',
        'active' => 'primary',
        'paused' => 'warning',
        'completed' => 'success',
        'cancelled' => 'neutral',
    ];

    /** Dự án còn mở: thành viên vẫn cập nhật tiến độ được. */
    public const OPEN_STATUSES = ['planning', 'active', 'paused'];

    protected $fillable = [
        'code', 'name', 'type', 'description', 'owner_id', 'start_date', 'deadline', 'status',
        'kickoff_notes', 'kickoff_link', 'plan_locked_at', 'plan_locked_by', 'completed_at', 'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'deadline' => 'date',
        'plan_locked_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (AcademicProject $project) {
            $project->code ??= app(DocumentCodeGenerator::class)->academicProjectCode();
        });
    }

    /** Người quản lý (academic_project.view_all / manage) thấy mọi dự án; người khác chỉ dự án mình phụ trách / tham gia / được giao mốc. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('academic_project.view_all') || $user->can('academic_project.manage')) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->where('owner_id', $user->id)
            ->orWhereHas('members', fn (Builder $m) => $m->whereKey($user->id))
            ->orWhereHas('milestones', fn (Builder $m) => $m->where('assignee_id', $user->id)));
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'plan_locked_by');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'academic_project_members')->withTimestamps();
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(AcademicProjectMilestone::class)->orderBy('due_date')->orderBy('sort_order')->orderBy('id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(AcademicProjectUpdate::class)->latest()->latest('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Người được cập nhật tiến độ: phụ trách, thành viên, người nhận mốc. */
    public function involves(User $user): bool
    {
        return $this->owner_id === $user->id
            || $this->members->contains('id', $user->id)
            || $this->milestones->contains('assignee_id', $user->id);
    }

    /** % tiến độ cả dự án (0–100); chưa có mốc → 0. Cần milestones đã nạp. */
    public function progressPercent(): int
    {
        $milestones = $this->milestones;

        return $milestones->isEmpty() ? 0 : (int) round($milestones->avg(fn (AcademicProjectMilestone $m) => $m->progressPercent()));
    }
}
