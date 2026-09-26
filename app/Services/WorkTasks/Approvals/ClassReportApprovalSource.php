<?php

namespace App\Services\WorkTasks\Approvals;

use App\Models\ClassReport;
use App\Models\User;
use App\Support\Approvals\ApprovalItem;
use App\Support\Approvals\ApprovalResult;
use App\Support\Approvals\ControllerActionInvoker;
use App\Support\Approvals\QueryApprovalSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Báo cáo trực lớp (không ảnh) chờ xác nhận (màn tasks.manual-approvals, mục báo cáo): chỉ đúng người xác nhận
 * theo A6 Q8 (ClassReport::isConfirmableBy — GV chính, lớp chưa có GV chính thì người giao việc).
 * SQL lọc thô theo các vai có thể xác nhận, rồi lọc đúng luật trong PHP (người xác nhận tính lại theo GV chính hiện tại).
 */
class ClassReportApprovalSource extends QueryApprovalSource
{
    public function __construct(private readonly ControllerActionInvoker $actions) {}

    public function key(): string
    {
        return 'class_report';
    }

    public function label(): string
    {
        return 'Báo cáo trực lớp';
    }

    public function group(): string
    {
        return self::GROUP_WORK;
    }

    public function indexUrl(): string
    {
        return route('tasks.manual-approvals', ['kind' => 'report']);
    }

    public function canView(User $user): bool
    {
        return $user->can('work_task.view') && $user->canAny(WorkTaskApprovalSource::ABILITIES);
    }

    protected function query(User $user): Builder
    {
        return ClassReport::query()
            ->where('status', ClassReport::STATUS_PENDING)
            ->where('reporter_id', '!=', $user->id)
            ->where(fn (Builder $q) => $q->where('confirmer_id', $user->id)
                ->orWhereHas('classModel', fn (Builder $c) => $c->where('teacher_id', $user->id))
                ->orWhereHas('task', fn (Builder $t) => $t->where('creator_id', $user->id)));
    }

    protected function with(): array
    {
        return ['classModel:id,name,code,teacher_id', 'task:id,creator_id,status', 'reporter:id,name'];
    }

    /** @return Collection<int, ClassReport> */
    private function confirmable(User $user, ?int $id = null): Collection
    {
        return $this->query($user)->with($this->with())
            ->when($id !== null, fn (Builder $q) => $q->whereKey($id))
            ->latest()->latest('id')->get()
            ->filter(fn (ClassReport $report) => $report->isConfirmableBy($user))
            ->values();
    }

    public function count(User $user): int
    {
        return $this->confirmable($user)->count();
    }

    public function pending(User $user, int $limit): Collection
    {
        return $this->confirmable($user)->take($limit)->map(fn (ClassReport $report) => $this->toItem($report))->values();
    }

    public function find(User $user, int $id): ?ApprovalItem
    {
        $report = $this->confirmable($user, $id)->first();

        return $report ? $this->toItem($report) : null;
    }

    /** @param  ClassReport  $model */
    protected function toItem(Model $model): ApprovalItem
    {
        return new ApprovalItem(
            source: $this->key(),
            id: $model->id,
            title: 'Báo cáo trực lớp '.$model->session_name,
            subtitle: collect([$model->classModel?->name, $model->reporter ? 'Người nộp: '.$model->reporter->name : null])->filter()->implode(' · '),
            url: route('tasks.manual-approvals', ['kind' => 'report', 'report' => $model->id]),
            createdAt: $model->created_at,
            meta: array_filter([
                'Lớp' => $model->classModel?->name,
                'Buổi' => $model->session_name,
                'Nội dung đã học' => self::limit($model->topics_learned, 400),
                'Người nộp' => $model->reporter?->name,
            ]),
        );
    }

    public function supports(User $user, string $action): bool
    {
        return true; // quyền theo từng báo cáo — đã lọc ở confirmable(), action gốc kiểm tra lại
    }

    public function approve(User $user, int $id): ApprovalResult
    {
        return $this->actions->run($user, 'tasks.class-reports.approve', ['id' => $id]);
    }

    public function reject(User $user, int $id, string $reason): ApprovalResult
    {
        return $this->actions->run($user, 'tasks.class-reports.reject', ['id' => $id], ['reason' => $reason]);
    }

    public function watchedModels(): array
    {
        return [ClassReport::class];
    }
}
