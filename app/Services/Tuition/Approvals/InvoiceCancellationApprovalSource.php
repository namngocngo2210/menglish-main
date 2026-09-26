<?php

namespace App\Services\Tuition\Approvals;

use App\Models\InvoiceCancellation;
use App\Models\User;
use App\Support\Approvals\ApprovalItem;
use App\Support\Approvals\ApprovalResult;
use App\Support\Approvals\ControllerActionInvoker;
use App\Support\Approvals\QueryApprovalSource;
use App\Support\TuitionBranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Yêu cầu hủy hóa đơn chờ duyệt (màn tuition.invoices.cancellations) — mặc định chỉ Admin (invoice.approve_cancel). */
class InvoiceCancellationApprovalSource extends QueryApprovalSource
{
    public function __construct(private readonly ControllerActionInvoker $actions) {}

    public function key(): string
    {
        return 'invoice_cancellation';
    }

    public function label(): string
    {
        return 'Yêu cầu hủy hóa đơn';
    }

    public function group(): string
    {
        return self::GROUP_TUITION;
    }

    public function indexUrl(): string
    {
        return route('tuition.invoices.cancellations', ['status' => 'pending']);
    }

    public function canView(User $user): bool
    {
        return self::allows($user, 'tuition.view', 'invoice.approve_cancel');
    }

    protected function query(User $user): Builder
    {
        return TuitionBranchScope::cancellations(InvoiceCancellation::query(), TuitionBranchScope::branchIds($user))
            ->where('status', 'pending');
    }

    protected function with(): array
    {
        return ['student:id,name,code', 'receipt:id,receipt_number', 'requester:id,name'];
    }

    /** @param  InvoiceCancellation  $model */
    protected function toItem(Model $model): ApprovalItem
    {
        return new ApprovalItem(
            source: $this->key(),
            id: $model->id,
            title: 'Hủy HĐ '.$model->invoice_number,
            subtitle: collect([$model->student?->name, self::limit($model->reason, 60)])->filter()->implode(' · '),
            url: route('tuition.invoices.cancellations', ['status' => 'pending', 'selected_id' => $model->id]),
            createdAt: $model->created_at,
            amount: (float) $model->amount,
            meta: array_filter([
                'Học viên' => $model->student ? $model->student->name.' ('.$model->student->code.')' : null,
                'Phiếu thu' => $model->receipt?->receipt_number,
                'Lý do hủy' => self::limit($model->reason, 300),
                'Người yêu cầu' => $model->requester?->name,
            ]),
        );
    }

    public function supports(User $user, string $action): bool
    {
        return $user->can('invoice.approve_cancel');
    }

    public function approve(User $user, int $id): ApprovalResult
    {
        return $this->actions->run($user, 'tuition.invoices.cancellations.approve', ['id' => $id]);
    }

    public function reject(User $user, int $id, string $reason): ApprovalResult
    {
        return $this->actions->run($user, 'tuition.invoices.cancellations.reject', ['id' => $id], ['rejection_reason' => $reason]);
    }

    public function watchedModels(): array
    {
        return [InvoiceCancellation::class];
    }
}
