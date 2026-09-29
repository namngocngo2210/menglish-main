<?php

namespace App\Services\Crm;

use App\Models\CrmBranchTransfer;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Đổi người phụ trách khách sang người ở cơ sở khác (chủ dự án 29/09/2026): cần Admin xác nhận chuyển cơ sở;
 * duyệt xong khách chuyển sang cơ sở của người phụ trách mới, học viên (nếu đã chốt) chuyển theo.
 * Người có quyền "Duyệt chuyển cơ sở" (Admin) đổi thì áp dụng ngay.
 */
class CrmBranchTransferService
{
    /** Giao $owner cho khách có phải chuyển cơ sở không. */
    public function needsTransfer(CrmCustomer $customer, User $owner): bool
    {
        return ! LeadOwners::belongsToBranch($owner, $customer->branch_id ? (int) $customer->branch_id : null);
    }

    /**
     * Đổi người phụ trách sang cơ sở khác: Admin → áp dụng ngay; người khác → tạo yêu cầu chờ Admin duyệt.
     * Trả về yêu cầu vừa tạo (null nếu đã áp dụng ngay).
     */
    public function requestOrApply(CrmCustomer $customer, User $owner, User $actor, ?string $reason): ?CrmBranchTransfer
    {
        $toBranchId = LeadOwners::homeBranchId($owner);
        if (! $toBranchId) {
            throw ValidationException::withMessages(['assigned_user_id' => "{$owner->name} chưa được gán cơ sở nên không chuyển khách sang được."]);
        }
        if ($customer->pendingBranchTransfer()->exists()) {
            throw ValidationException::withMessages(['assigned_user_id' => 'Khách đang có yêu cầu chuyển cơ sở chờ Admin duyệt. Chờ duyệt xong rồi đổi tiếp.']);
        }

        return DB::transaction(function () use ($customer, $owner, $actor, $reason, $toBranchId) {
            $transfer = CrmBranchTransfer::create([
                'customer_id' => $customer->id,
                'from_branch_id' => $customer->branch_id,
                'to_branch_id' => $toBranchId,
                'from_user_id' => $customer->assigned_user_id,
                'to_user_id' => $owner->id,
                'requested_by' => $actor->id,
                'reason' => $reason,
                'status' => CrmBranchTransfer::STATUS_PENDING,
            ]);

            if ($actor->can('lead.approve_transfer')) {
                $this->apply($transfer, $actor, null);

                return null;
            }

            $transfer->load(['fromBranch', 'toBranch']);
            CrmCustomerHistory::create([
                'customer_id' => $customer->id,
                'user_id' => $actor->id,
                'type' => 'assign',
                'reason' => $reason,
                'content' => "Gửi yêu cầu chuyển người phụ trách sang {$owner->name} và chuyển cơ sở "
                    .($transfer->fromBranch?->name ?? '(chưa có)').' → '.($transfer->toBranch?->name ?? '')
                    .'. Chờ Admin duyệt.'.($reason ? " Lý do: {$reason}" : ''),
            ]);

            return $transfer;
        });
    }

    public function approve(CrmBranchTransfer $transfer, User $actor, ?string $note = null): void
    {
        DB::transaction(function () use ($transfer, $actor, $note) {
            $transfer = CrmBranchTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->assertPending($transfer);
            $this->apply($transfer, $actor, $note);
        });
    }

    public function reject(CrmBranchTransfer $transfer, User $actor, string $reason): void
    {
        DB::transaction(function () use ($transfer, $actor, $reason) {
            $transfer = CrmBranchTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->assertPending($transfer);
            $transfer->update([
                'status' => CrmBranchTransfer::STATUS_REJECTED,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $reason,
            ]);
            $transfer->load(['toUser', 'toBranch']);
            CrmCustomerHistory::create([
                'customer_id' => $transfer->customer_id,
                'user_id' => $actor->id,
                'type' => 'assign',
                'reason' => $reason,
                'content' => 'Từ chối chuyển người phụ trách sang '.($transfer->toUser?->name ?? '').' ('.($transfer->toBranch?->name ?? '')
                    ."). Khách giữ cơ sở và người phụ trách cũ. Lý do: {$reason}",
            ]);
        });
    }

    /** Đổi người phụ trách + cơ sở của khách, học viên đã chốt (và cơ sở chờ xếp lớp). */
    protected function apply(CrmBranchTransfer $transfer, User $actor, ?string $note): void
    {
        $customer = CrmCustomer::query()->lockForUpdate()->findOrFail($transfer->customer_id);
        $owner = User::withTrashed()->findOrFail($transfer->to_user_id);
        if (! LeadOwners::isCandidate($owner)) {
            throw ValidationException::withMessages(['assigned_user_id' => "{$owner->name} không còn là người phụ trách khách được (tài khoản khóa hoặc đổi vai trò)."]);
        }

        $transfer->update([
            'status' => CrmBranchTransfer::STATUS_APPROVED,
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ]);

        $toBranchId = (int) $transfer->to_branch_id;
        $customer->update(array_filter([
            'assigned_user_id' => $owner->id,
            'branch_id' => $toBranchId,
            // Khách đang Chờ xếp lớp: xếp lớp ở cơ sở mới.
            'waiting_branch_id' => $customer->waiting_branch_id ? $toBranchId : null,
        ], fn ($value) => $value !== null));

        $studentNote = '';
        if ($customer->converted_student_id && ($student = Student::find($customer->converted_student_id))) {
            $student->update(['branch_id' => $toBranchId]);
            $studentNote = " Học viên {$student->name} chuyển sang cơ sở mới.";
        }

        $transfer->load(['fromBranch', 'toBranch', 'fromUser']);
        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => $actor->id,
            'type' => 'assign',
            'reason' => $transfer->reason,
            'changes' => [
                'assigned_user_id' => ['label' => 'Người phụ trách', 'old' => $transfer->fromUser?->name, 'new' => $owner->name],
                'branch_id' => ['label' => 'Cơ sở', 'old' => $transfer->fromBranch?->name, 'new' => $transfer->toBranch?->name],
            ],
            'content' => ((int) $transfer->requested_by === $actor->id ? 'Chuyển' : 'Admin duyệt chuyển')
                ." người phụ trách sang {$owner->name}, chuyển cơ sở "
                .($transfer->fromBranch?->name ?? '(chưa có)').' → '.($transfer->toBranch?->name ?? '').'.'
                .$studentNote
                .($transfer->reason ? " Lý do: {$transfer->reason}" : '')
                .($note ? " Ghi chú duyệt: {$note}" : ''),
        ]);
    }

    protected function assertPending(CrmBranchTransfer $transfer): void
    {
        if ($transfer->status !== CrmBranchTransfer::STATUS_PENDING) {
            throw ValidationException::withMessages(['transfer' => 'Yêu cầu này đã được xử lý.']);
        }
    }
}
