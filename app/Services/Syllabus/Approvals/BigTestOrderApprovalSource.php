<?php

namespace App\Services\Syllabus\Approvals;

use App\Models\BigTestOrder;
use App\Models\User;
use App\Support\Approvals\ApprovalItem;
use App\Support\Approvals\ApprovalResult;
use App\Support\Approvals\ControllerActionInvoker;
use App\Support\Approvals\QueryApprovalSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Order đề Big Test chờ duyệt & phân phối (màn syllabus.big-tests.distribution). Duyệt cần link đề + lịch/phòng thi
 * → làm ở màn gốc; inbox chỉ hỗ trợ từ chối hàng loạt.
 */
class BigTestOrderApprovalSource extends QueryApprovalSource
{
    public function __construct(private readonly ControllerActionInvoker $actions) {}

    public function key(): string
    {
        return 'big_test_order';
    }

    public function label(): string
    {
        return 'Phân phối đề Big Test';
    }

    public function group(): string
    {
        return self::GROUP_ACADEMIC;
    }

    public function indexUrl(): string
    {
        return route('syllabus.big-tests.distribution', ['order_status' => 'pending']);
    }

    public function canView(User $user): bool
    {
        return self::allows($user, 'syllabus.view', 'big_test.approve');
    }

    protected function query(User $user): Builder
    {
        return BigTestOrder::query()->visibleTo($user)->where('status', 'pending');
    }

    protected function with(): array
    {
        return ['classModel:id,name,code', 'teacher:id,name', 'stage'];
    }

    /** @param  BigTestOrder  $model */
    protected function toItem(Model $model): ApprovalItem
    {
        return new ApprovalItem(
            source: $this->key(),
            id: $model->id,
            title: 'Order '.$model->code.' · '.($model->classModel?->name ?? 'Lớp #'.$model->class_id),
            subtitle: collect([$model->type_label.' '.$model->stage_label, $model->teacher?->name, $model->due_date ? 'hạn '.$model->due_date->format('d/m/Y') : null])->filter()->implode(' · '),
            url: route('syllabus.big-tests.distribution', ['order' => $model->id, 'order_status' => 'pending']),
            createdAt: $model->created_at,
            meta: array_filter([
                'Lớp' => $model->classModel?->name,
                'Loại đề' => $model->type_label,
                'Chặng' => $model->stage_label,
                'Giáo viên' => $model->teacher?->name,
                'Hạn duyệt' => $model->due_date?->format('d/m/Y'),
            ]),
            flag: $model->isOverdue() ? 'Quá hạn' : null,
        );
    }

    public function supports(User $user, string $action): bool
    {
        return $action === self::REJECT && $user->can('big_test.approve');
    }

    public function reject(User $user, int $id, string $reason): ApprovalResult
    {
        return $this->actions->run($user, 'syllabus.big-tests.orders.reject', ['id' => $id], ['rejection_reason' => $reason]);
    }

    public function watchedModels(): array
    {
        return [BigTestOrder::class];
    }
}
