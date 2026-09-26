<?php

namespace App\Services\Syllabus\Approvals;

use App\Models\SyllabusChangeProposal;
use App\Models\User;
use App\Support\Approvals\ApprovalItem;
use App\Support\Approvals\ApprovalResult;
use App\Support\Approvals\ControllerActionInvoker;
use App\Support\Approvals\QueryApprovalSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Đề xuất sửa giáo trình chờ Học thuật duyệt (màn syllabus.versions). */
class ProposalApprovalSource extends QueryApprovalSource
{
    public function __construct(private readonly ControllerActionInvoker $actions) {}

    public function key(): string
    {
        return 'syllabus_proposal';
    }

    public function label(): string
    {
        return 'Đề xuất sửa giáo trình';
    }

    public function group(): string
    {
        return self::GROUP_ACADEMIC;
    }

    public function indexUrl(): string
    {
        return route('syllabus.versions', ['status' => 'pending']);
    }

    public function canView(User $user): bool
    {
        return self::allows($user, 'syllabus.view', 'syllabus.approve_adjustment');
    }

    protected function query(User $user): Builder
    {
        return SyllabusChangeProposal::query()->visibleTo($user)->where('status', 'pending');
    }

    protected function with(): array
    {
        return ['curriculum:id,title', 'unit', 'lesson', 'proposer:id,name'];
    }

    /** @param  SyllabusChangeProposal  $model */
    protected function toItem(Model $model): ApprovalItem
    {
        return new ApprovalItem(
            source: $this->key(),
            id: $model->id,
            title: trim(($model->curriculum?->title ?? 'Giáo trình').' — '.$model->target_label, ' —'),
            subtitle: collect([$model->proposer?->name, self::limit($model->new_content, 70)])->filter()->implode(' · '),
            url: route('syllabus.versions', ['proposal' => $model->id]),
            createdAt: $model->created_at,
            meta: array_filter([
                'Giáo trình' => $model->curriculum?->title,
                'Vị trí' => $model->target_label,
                'Loại đề xuất' => $model->proposal_type,
                'Nội dung đề xuất' => self::limit($model->new_content, 400),
                'Lý do' => self::limit($model->reason, 300),
                'Người đề xuất' => $model->proposer?->name,
            ]),
        );
    }

    public function supports(User $user, string $action): bool
    {
        return $user->can('syllabus.approve_adjustment');
    }

    public function approve(User $user, int $id): ApprovalResult
    {
        return $this->actions->run($user, 'syllabus.proposals.approve', ['id' => $id]);
    }

    public function reject(User $user, int $id, string $reason): ApprovalResult
    {
        return $this->actions->run($user, 'syllabus.proposals.reject', ['id' => $id], ['review_note' => $reason]);
    }

    public function watchedModels(): array
    {
        return [SyllabusChangeProposal::class];
    }
}
