<?php

namespace App\Models;

use App\Models\Concerns\ScopedToViewer;
use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Báo cáo họp giáo viên theo tuần (Học thuật ghi): nội dung chuyên môn của một giáo viên / lớp + đề xuất và tình trạng xử lý. */
class TeacherMeetingReport extends Model
{
    use ScopedToViewer, SoftDeletes;

    public const STATUSES = [
        'handled' => 'Đã xử lý',
        'in_progress' => 'Đang xử lý',
        'sketchy' => 'GV báo cáo sơ sài',
        'not_reported' => 'GV chưa báo cáo',
        'duplicate' => 'Báo cáo trùng lặp',
    ];

    public const STATUS_COLORS = [
        'handled' => 'success',
        'in_progress' => 'primary',
        'sketchy' => 'warning',
        'not_reported' => 'error',
        'duplicate' => 'secondary',
    ];

    protected $fillable = [
        'week_start', 'teacher_id', 'class_id', 'author_id',
        'syllabus_note', 'scores_note', 'class_note', 'academic_order', 'recommendation', 'status',
    ];

    protected $casts = [
        'week_start' => 'date',
    ];

    /**
     * Theo phạm vi Lớp học: Toàn hệ thống → mọi báo cáo; còn lại → báo cáo gắn lớp trong phạm vi, hoặc báo cáo
     * không gắn lớp do mình ghi / (mức Chi nhánh) về giáo viên thuộc chi nhánh của mình (chi nhánh chính hoặc
     * chi nhánh được cấp thêm). Tự áp dụng trong request web (ScopedToViewer).
     */
    public static function constrainToViewer(Builder $query, User $user): void
    {
        $level = DataScope::level($user, 'class');
        if ($level === DataScope::ALL) {
            return;
        }
        $branchIds = $level === DataScope::BRANCH ? $user->branchIds() : [];

        $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $noClass) => $noClass->whereNull('class_id')
                ->where(function (Builder $owned) use ($user, $branchIds) {
                    $owned->where('author_id', $user->id);
                    if ($branchIds !== []) {
                        $owned->orWhereHas('teacher', fn (Builder $t) => $t->whereIn('branch_id', $branchIds)
                            ->orWhereHas('branches', fn (Builder $b) => $b->whereIn('branches.id', $branchIds)));
                    }
                }))
            ->orWhereHas('classModel', fn (Builder $c) => $c->visibleTo($user)));
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id')->withTrashed();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
