<?php

namespace App\Services\Syllabus\Approvals;

use App\Models\SyllabusAdjustmentRequest;
use App\Models\User;
use App\Support\Approvals\ApprovalItem;
use App\Support\Approvals\ApprovalResult;
use App\Support\Approvals\ControllerActionInvoker;
use App\Support\Approvals\QueryApprovalSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Yêu cầu điều chỉnh (giãn) tiến độ chờ duyệt (màn syllabus.adjustment-requests). Duyệt từ inbox dùng đúng số buổi
 * GV xin (không sửa được như ở màn gốc) — action gốc tự thêm buổi theo TKB, không thêm được thì không duyệt.
 */
class AdjustmentApprovalSource extends QueryApprovalSource
{
    public function __construct(private readonly ControllerActionInvoker $actions) {}

    public function key(): string
    {
        return 'syllabus_adjustment';
    }

    public function label(): string
    {
        return 'Điều chỉnh tiến độ';
    }

    public function group(): string
    {
        return self::GROUP_ACADEMIC;
    }

    public function indexUrl(): string
    {
        return route('syllabus.adjustment-requests', ['status' => 'pending']);
    }

    public function canView(User $user): bool
    {
        return self::allows($user, 'syllabus.view', 'syllabus.approve_adjustment');
    }

    protected function query(User $user): Builder
    {
        // Người duyệt thấy mọi yêu cầu (như màn gốc).
        return SyllabusAdjustmentRequest::query()->where('status', 'pending');
    }

    protected function with(): array
    {
        return ['classModel:id,name,code', 'teacher:id,name', 'assignment'];
    }

    /** @param  SyllabusAdjustmentRequest  $model */
    protected function toItem(Model $model): ApprovalItem
    {
        return new ApprovalItem(
            source: $this->key(),
            id: $model->id,
            title: $model->class_stage_label.': '.$model->request_type,
            subtitle: collect([$model->teacher?->name, self::limit($model->reason, 70)])->filter()->implode(' · '),
            url: route('syllabus.adjustment-requests', ['request' => $model->id]),
            createdAt: $model->created_at,
            meta: array_filter([
                'Lớp / chặng' => $model->class_stage_label,
                'Yêu cầu' => $model->request_type,
                'Số buổi cần thêm' => $model->extra_sessions ? (string) $model->extra_sessions : null,
                'Lý do' => self::limit($model->reason, 400),
                'Giáo viên' => $model->teacher?->name,
                'Hạn SLA' => $model->sla_due_at?->format('H:i d/m/Y'),
            ]),
            flag: $model->isSlaOverdue() ? 'Quá SLA' : null,
        );
    }

    public function supports(User $user, string $action): bool
    {
        return $user->can('syllabus.approve_adjustment');
    }

    public function approve(User $user, int $id): ApprovalResult
    {
        return $this->actions->run($user, 'syllabus.adjustment-requests.approve', ['id' => $id]);
    }

    public function reject(User $user, int $id, string $reason): ApprovalResult
    {
        return $this->actions->run($user, 'syllabus.adjustment-requests.reject', ['id' => $id], ['rejection_reason' => $reason]);
    }

    public function watchedModels(): array
    {
        return [SyllabusAdjustmentRequest::class];
    }
}
