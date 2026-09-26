<?php

namespace App\Services\WorkTasks\Approvals;

use App\Models\User;
use App\Models\WorkTask;
use App\Support\Approvals\ApprovalItem;
use App\Support\Approvals\ApprovalResult;
use App\Support\Approvals\ControllerActionInvoker;
use App\Support\Approvals\QueryApprovalSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Việc chờ xác nhận hoàn thành (màn tasks.manual-approvals): người giao việc hoặc người có work_task.approve
 * (trong phạm vi), không tự duyệt việc của mình — luật ở WorkTask::scopeAwaitingConfirmationBy + action gốc.
 */
class WorkTaskApprovalSource extends QueryApprovalSource
{
    /** Chỉ người có thể là "người giao" / người duyệt mới có việc chờ mình xác nhận. */
    public const ABILITIES = ['work_task.approve', 'work_task.create', 'work_task.request'];

    public function __construct(private readonly ControllerActionInvoker $actions) {}

    public function key(): string
    {
        return 'work_task';
    }

    public function label(): string
    {
        return 'Xác nhận hoàn thành việc';
    }

    public function group(): string
    {
        return self::GROUP_WORK;
    }

    public function indexUrl(): string
    {
        return route('tasks.manual-approvals', ['kind' => 'task']);
    }

    public function canView(User $user): bool
    {
        return $user->can('work_task.view') && $user->canAny(self::ABILITIES);
    }

    protected function query(User $user): Builder
    {
        return WorkTask::query()->awaitingConfirmationBy($user);
    }

    protected function with(): array
    {
        return ['assignee:id,name', 'creator:id,name', 'classModel:id,name,code'];
    }

    /** @param  WorkTask  $model */
    protected function toItem(Model $model): ApprovalItem
    {
        return new ApprovalItem(
            source: $this->key(),
            id: $model->id,
            title: $model->title,
            subtitle: collect([$model->assignee ? 'Người làm: '.$model->assignee->name : null, $model->classModel?->name])->filter()->implode(' · '),
            url: route('tasks.manual-approvals', ['selected_id' => $model->id]),
            createdAt: $model->updated_at,
            modalUrl: route('tasks.show', $model->id),
            meta: array_filter([
                'Người làm' => $model->assignee?->name,
                'Người giao' => $model->creator?->name,
                'Lớp' => $model->classModel?->name,
                'Hạn' => $model->due_date?->format('d/m/Y'),
                'Báo cáo hoàn thành' => self::limit($model->completion_note, 400),
            ]),
        );
    }

    public function pending(User $user, int $limit): Collection
    {
        // Màn gốc xếp theo lúc gửi chờ xác nhận (updated_at).
        return $this->query($user)->with($this->with())->latest('updated_at')->latest('id')->limit($limit)->get()
            ->map(fn (WorkTask $task) => $this->toItem($task))->values();
    }

    public function supports(User $user, string $action): bool
    {
        return true; // quyền theo từng việc — action gốc kiểm tra (ensureCanApprove)
    }

    public function approve(User $user, int $id): ApprovalResult
    {
        return $this->actions->run($user, 'tasks.approve', ['id' => $id]);
    }

    public function reject(User $user, int $id, string $reason): ApprovalResult
    {
        return $this->actions->run($user, 'tasks.reject', ['id' => $id], ['admin_note' => $reason]);
    }

    public function watchedModels(): array
    {
        return [WorkTask::class];
    }
}
