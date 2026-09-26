<?php

namespace App\Services\Tuition\Approvals;

use App\Models\TuitionRefundRequest;
use App\Models\User;
use App\Support\Approvals\ApprovalItem;
use App\Support\Approvals\ApprovalResult;
use App\Support\Approvals\ControllerActionInvoker;
use App\Support\Approvals\QueryApprovalSource;
use App\Support\TuitionBranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Hoàn tiền / chuyển nhượng / khất nợ / bảo lưu chờ duyệt (màn tuition.refunds) — chỉ loại hồ sơ user được duyệt
 * (TuitionRefundRequest::approvePermission). Duyệt cần ảnh chứng từ + quyết định thu hồi hoa hồng → làm ở màn gốc;
 * inbox chỉ hỗ trợ từ chối hàng loạt.
 */
class RefundApprovalSource extends QueryApprovalSource
{
    public function __construct(private readonly ControllerActionInvoker $actions) {}

    public function key(): string
    {
        return 'refund';
    }

    public function label(): string
    {
        return 'Hoàn tiền & khất nợ';
    }

    public function group(): string
    {
        return self::GROUP_TUITION;
    }

    public function indexUrl(): string
    {
        return route('tuition.refunds', ['status' => 'pending']);
    }

    public function canView(User $user): bool
    {
        return $user->can('tuition.view') && $this->approvableTypes($user) !== [];
    }

    /** @return list<string> */
    private function approvableTypes(User $user): array
    {
        return collect(array_keys(TuitionRefundRequest::TYPES))
            ->filter(fn (string $type) => $user->can(TuitionRefundRequest::approvePermission($type)))
            ->values()->all();
    }

    protected function query(User $user): Builder
    {
        $scope = TuitionBranchScope::branchIds($user);

        return TuitionRefundRequest::query()
            ->where('status', 'pending')
            ->whereIn('type', $this->approvableTypes($user))
            ->when($scope !== null, fn (Builder $q) => $q->whereHas('student', fn (Builder $s) => TuitionBranchScope::students($s, $scope)));
    }

    protected function with(): array
    {
        return ['student:id,name,code', 'targetStudent:id,name,code', 'requester:id,name'];
    }

    /** @param  TuitionRefundRequest  $model */
    protected function toItem(Model $model): ApprovalItem
    {
        $overdue = $model->isProcessingOverdue();

        return new ApprovalItem(
            source: $this->key(),
            id: $model->id,
            title: $model->type_label.' · '.($model->student?->name ?? 'Học viên #'.$model->student_id),
            subtitle: $model->targetStudent ? 'Chuyển sang '.$model->targetStudent->name : self::limit($model->reason, 60),
            url: route('tuition.refunds', ['status' => 'pending']),
            createdAt: $model->created_at,
            amount: (float) $model->refund_amount > 0 ? (float) $model->refund_amount : null,
            meta: array_filter([
                'Loại hồ sơ' => $model->type_label,
                'Học viên' => $model->student ? $model->student->name.' ('.$model->student->code.')' : null,
                'Người nhận chuyển nhượng' => $model->targetStudent?->name,
                'Hạn xử lý' => $model->processing_deadline?->format('d/m/Y'),
                'Người đề nghị' => $model->requester?->name,
            ]),
            flag: $overdue ? 'Quá hạn xử lý' : null,
        );
    }

    public function supports(User $user, string $action): bool
    {
        return $action === self::REJECT && $user->can(TuitionRefundRequest::REJECT_PERMISSION);
    }

    public function reject(User $user, int $id, string $reason): ApprovalResult
    {
        return $this->actions->run($user, 'tuition.refunds.reject', ['id' => $id], ['rejection_reason' => $reason]);
    }

    public function watchedModels(): array
    {
        return [TuitionRefundRequest::class];
    }
}
