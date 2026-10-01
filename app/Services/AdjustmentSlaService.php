<?php

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\User;
use App\Support\Rbac;
use Illuminate\Support\Carbon;

/**
 * Yêu cầu giãn tiến độ giáo trình phải được duyệt trong 3 ngày (SyllabusAdjustmentRequest::SLA_DAYS).
 * Người duyệt = người có quyền syllabus.approve_adjustment (Học thuật, và Admin qua quyền mặc định); thông báo cá nhân:
 *  - khi GV gửi yêu cầu;
 *  - đúng 1 lần khi yêu cầu vẫn chờ duyệt mà quá hạn SLA (sla_notified_at).
 */
class AdjustmentSlaService
{
    /** @return \Illuminate\Support\Collection<int, User> */
    public function approvers(): \Illuminate\Support\Collection
    {
        return Rbac::scopeUsersWithPermission(User::query(), 'syllabus.approve_adjustment')
            ->where('is_active', true)->whereNull('locked_at')->orderBy('id')->get();
    }

    public function notifyCreated(SyllabusAdjustmentRequest $req): int
    {
        $className = $req->classModel?->name ?? 'lớp #'.$req->class_id;
        $count = 0;
        foreach ($this->approvers()->reject(fn (User $u) => $u->id === $req->user_id) as $approver) {
            AdminNotification::create([
                'user_id' => $approver->id,
                'type' => 'adjustment_pending',
                'title' => "Yêu cầu giãn tiến độ mới: {$className}",
                'message' => ($req->teacher?->name ?? 'Giáo viên')." gửi \"{$req->request_type}\" — hạn duyệt ".$req->sla_due_at?->format('H:i d/m/Y')
                    .' ('.SyllabusAdjustmentRequest::SLA_DAYS.' ngày).',
                'data' => ['request_id' => $req->id, 'link' => route('syllabus.adjustment-requests', ['request' => $req->id])],
                'is_read' => false,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Yêu cầu còn chờ duyệt quá SLA → báo người duyệt đúng 1 lần.
     *
     * @return int số yêu cầu đã báo
     */
    public function notifyBreaches(?Carbon $now = null): int
    {
        $now ??= now();
        $requests = SyllabusAdjustmentRequest::with(['classModel', 'teacher'])
            ->where('status', 'pending')
            ->whereNull('sla_notified_at')
            ->where('created_at', '<=', $now->copy()->subHours(SyllabusAdjustmentRequest::SLA_HOURS))
            ->whereHas('classModel') // bỏ lớp đã xóa
            ->orderBy('id')
            ->get();
        if ($requests->isEmpty()) {
            return 0;
        }

        $approvers = $this->approvers();
        foreach ($requests as $req) {
            foreach ($approvers as $approver) {
                AdminNotification::create([
                    'user_id' => $approver->id,
                    'type' => 'adjustment_sla',
                    'title' => "Quá hạn duyệt giãn tiến độ: {$req->classModel->name}",
                    'message' => ($req->teacher?->name ?? 'Giáo viên').' gửi "'.$req->request_type.'" ngày '.$req->created_at->format('d/m/Y')
                        .' — đã quá '.SyllabusAdjustmentRequest::SLA_DAYS.' ngày chưa được duyệt.',
                    'data' => ['request_id' => $req->id, 'link' => route('syllabus.adjustment-requests', ['request' => $req->id])],
                    'is_read' => false,
                ]);
            }
            $req->forceFill(['sla_notified_at' => $now])->save();
        }

        return $requests->count();
    }
}
