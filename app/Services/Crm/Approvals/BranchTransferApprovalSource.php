<?php

namespace App\Services\Crm\Approvals;

use App\Models\CrmBranchTransfer;
use App\Models\User;
use App\Services\Crm\CrmBranchTransferService;
use App\Support\Approvals\ApprovalItem;
use App\Support\Approvals\ApprovalResult;
use App\Support\Approvals\QueryApprovalSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * "Chuyển cơ sở (CRM)": đổi người phụ trách khách sang người ở cơ sở khác, chờ Admin (lead.approve_transfer) duyệt.
 * Duyệt / từ chối ngay trong hộp Việc cần duyệt.
 */
class BranchTransferApprovalSource extends QueryApprovalSource
{
    public function __construct(private readonly CrmBranchTransferService $transfers) {}

    public function key(): string
    {
        return 'crm_branch_transfer';
    }

    public function label(): string
    {
        return 'Chuyển cơ sở (CRM)';
    }

    public function group(): string
    {
        return self::GROUP_ACADEMIC;
    }

    public function indexUrl(): string
    {
        return route('approvals.index', ['group' => \App\Support\Approvals\ApprovalInboxService::groupSlug($this->group())]);
    }

    public function canView(User $user): bool
    {
        return $user->can('lead.approve_transfer');
    }

    protected function query(User $user): Builder
    {
        return CrmBranchTransfer::query()->pending()->whereHas('customer');
    }

    protected function with(): array
    {
        return ['customer:id,name,code,converted_student_id', 'fromBranch:id,name', 'toBranch:id,name', 'fromUser:id,name', 'toUser:id,name', 'requester:id,name'];
    }

    /** @param  CrmBranchTransfer  $model */
    protected function toItem(Model $model): ApprovalItem
    {
        $customer = $model->customer;

        return new ApprovalItem(
            source: $this->key(),
            id: $model->id,
            title: ($customer?->name ?? 'Khách #'.$model->customer_id).': '.($model->fromBranch?->name ?? '(chưa có)').' → '.($model->toBranch?->name ?? ''),
            subtitle: 'Người phụ trách mới: '.($model->toUser?->name ?? '').' · Người gửi: '.($model->requester?->name ?? ''),
            url: route('crm.customers.show', $model->customer_id),
            createdAt: $model->created_at,
            meta: array_filter([
                'Khách' => $customer ? $customer->name.' ('.$customer->short_code.')' : null,
                'Cơ sở hiện tại' => $model->fromBranch?->name ?? '(chưa có)',
                'Chuyển sang cơ sở' => $model->toBranch?->name,
                'Người phụ trách hiện tại' => $model->fromUser?->name ?? 'Chưa phân công',
                'Người phụ trách mới' => $model->toUser?->name,
                'Học viên' => $customer?->converted_student_id ? 'Đã chốt, học viên chuyển cơ sở theo' : null,
                'Người gửi' => $model->requester?->name,
                'Lý do' => $model->reason,
            ]),
        );
    }

    public function supports(User $user, string $action): bool
    {
        return $this->canView($user);
    }

    public function approve(User $user, int $id): ApprovalResult
    {
        $transfer = $this->query($user)->whereKey($id)->first();
        if (! $transfer) {
            return ApprovalResult::failure('Yêu cầu không còn chờ duyệt.');
        }
        try {
            $this->transfers->approve($transfer, $user);
        } catch (ValidationException $e) {
            return ApprovalResult::failure(collect($e->errors())->flatten()->first() ?? 'Không duyệt được.');
        }

        return ApprovalResult::success('Đã chuyển cơ sở cho khách.');
    }

    public function reject(User $user, int $id, string $reason): ApprovalResult
    {
        $transfer = $this->query($user)->whereKey($id)->first();
        if (! $transfer) {
            return ApprovalResult::failure('Yêu cầu không còn chờ duyệt.');
        }
        try {
            $this->transfers->reject($transfer, $user, $reason);
        } catch (ValidationException $e) {
            return ApprovalResult::failure(collect($e->errors())->flatten()->first() ?? 'Không từ chối được.');
        }

        return ApprovalResult::success('Đã từ chối chuyển cơ sở.');
    }

    public function watchedModels(): array
    {
        return [CrmBranchTransfer::class];
    }
}
