<?php

namespace App\Services\Tuition\Approvals;

use App\Models\TuitionReceipt;
use App\Models\User;
use App\Support\Approvals\ApprovalItem;
use App\Support\Approvals\ApprovalResult;
use App\Support\Approvals\ControllerActionInvoker;
use App\Support\Approvals\QueryApprovalSource;
use App\Support\TuitionBranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Phiếu thu chờ duyệt (màn tuition.receipts.approve) — duyệt = phát hành HĐĐT, dùng đúng action của màn gốc. */
class ReceiptApprovalSource extends QueryApprovalSource
{
    public function __construct(private readonly ControllerActionInvoker $actions) {}

    public function key(): string
    {
        return 'receipt';
    }

    public function label(): string
    {
        return 'Phiếu thu chờ duyệt';
    }

    public function group(): string
    {
        return self::GROUP_TUITION;
    }

    public function indexUrl(): string
    {
        return route('tuition.receipts.approve', ['status' => TuitionReceipt::STATUS_PENDING]);
    }

    public function canView(User $user): bool
    {
        return self::allows($user, 'tuition.view', 'tuition.approve');
    }

    protected function query(User $user): Builder
    {
        return TuitionBranchScope::receipts(TuitionReceipt::query(), TuitionBranchScope::branchIds($user))
            ->where('status', TuitionReceipt::STATUS_PENDING);
    }

    protected function with(): array
    {
        return ['tuition.student:id,name,code', 'student:id,name,code', 'creator:id,name'];
    }

    /** @param  TuitionReceipt  $model */
    protected function toItem(Model $model): ApprovalItem
    {
        $student = $model->tuition?->student ?? $model->student;
        $method = TuitionReceipt::METHOD_LABELS[$model->payment_method] ?? $model->payment_method;

        return new ApprovalItem(
            source: $this->key(),
            id: $model->id,
            title: 'Phiếu thu '.$model->receipt_number,
            subtitle: collect([$student?->name ?? $model->payer_name, $method])->filter()->implode(' · '),
            url: route('tuition.receipts.approve', ['status' => TuitionReceipt::STATUS_PENDING, 'selected_id' => $model->id]),
            createdAt: $model->created_at,
            amount: (float) $model->amount,
            meta: array_filter([
                'Học viên' => $student ? $student->name.' ('.$student->code.')' : $model->payer_name,
                'Hình thức' => $method,
                'Mã giao dịch' => $model->transaction_code,
                'Người lập' => $model->creator?->name,
                'Ghi chú' => self::limit($model->notes, 200),
            ]),
        );
    }

    public function supports(User $user, string $action): bool
    {
        return $user->can($action === self::APPROVE ? 'tuition.approve' : 'tuition.reject');
    }

    public function approve(User $user, int $id): ApprovalResult
    {
        return $this->actions->run($user, 'tuition.receipts.approve.action', ['id' => $id]);
    }

    public function reject(User $user, int $id, string $reason): ApprovalResult
    {
        return $this->actions->run($user, 'tuition.receipts.reject.action', ['id' => $id], ['rejection_reason' => $reason]);
    }

    public function watchedModels(): array
    {
        return [TuitionReceipt::class];
    }
}
