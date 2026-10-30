<?php

namespace App\Services\Syllabus;

use App\Models\SyllabusAssignment;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Rbac;

/**
 * Người Học thuật nhận việc của chặng lớp (việc cuối chặng, việc chuẩn bị đề theo order của GV): người giao chặng (còn quyền),
 * không thì người có quyền giao chặng gắn chi nhánh lớp (không tính Super Admin — ai cũng "phủ" mọi chi nhánh), không thì người
 * có quyền bất kỳ ngoài Super Admin, cuối cùng mới tới Super Admin (câu 10).
 */
class AcademicAssignee
{
    public function for(SyllabusAssignment $assignment): ?User
    {
        $opener = $assignment->opened_by ? User::where('is_active', true)->find($assignment->opened_by) : null;
        if ($opener?->can('syllabus.assign')) {
            return $opener;
        }
        $candidates = User::whereIn('id', NotificationService::userIdsWithPermission('syllabus.assign'))->orderBy('id')->get();

        [$admins, $others] = $candidates->partition(fn (User $u) => $u->hasRole(Rbac::SUPER_ADMIN));
        $branchId = (int) $assignment->loadMissing('classModel')->classModel?->branch_id;

        return $others->first(fn (User $u) => in_array($branchId, $u->branchIds(), true)) ?? $others->first() ?? $admins->first();
    }
}
