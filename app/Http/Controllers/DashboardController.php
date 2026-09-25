<?php

namespace App\Http\Controllers;

use App\Models\BigTest;
use App\Models\ClassModel;
use App\Models\CrmCustomer;
use App\Models\Student;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Models\WorkTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dashboard theo vai trò (BPMN bước 22):
 *  - Admin: số liệu toàn hệ thống.
 *  - Quản lý cơ sở: cùng bộ số liệu nhưng giới hạn chi nhánh mình.
 *  - Học thuật (academic_lead): lớp đang chạy, đề xuất giáo trình/giãn tiến độ chờ duyệt, Big Test sắp tới.
 * Vai trò khác giữ lưới lối tắt theo quyền như trước.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $roleDashboard = null;

        if ($user->hasRole('admin')) {
            $roleDashboard = $this->operationsDashboard(null, 'Toàn hệ thống');
        } elseif ($user->hasRole('manager')) {
            $branchIds = $user->managedBranchIds() ?? [];
            $roleDashboard = $this->operationsDashboard($branchIds, $user->branch?->name ?? 'Chi nhánh của bạn');
        } elseif ($user->hasRole('academic_lead')) {
            $roleDashboard = $this->academicDashboard($user);
        }

        return view('dashboard', compact('roleDashboard'));
    }

    /**
     * @param  int[]|null  $branchIds  null = toàn hệ thống
     */
    private function operationsDashboard(?array $branchIds, string $scopeLabel): array
    {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $inBranches = fn (Builder $query, string $column = 'branch_id') => $branchIds === null
            ? $query
            : $query->whereIn($column, $branchIds);

        $revenue = TuitionReceipt::query()
            ->where('status', TuitionReceipt::STATUS_APPROVED)
            ->whereBetween('payment_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->when($branchIds !== null, fn (Builder $q) => $q->whereHas('student', fn (Builder $s) => $s->whereIn('branch_id', $branchIds)))
            ->sum('amount');

        $studying = $inBranches(Student::query()->where('status', 'studying'))->count();
        $newLeads = $inBranches(CrmCustomer::query()->whereBetween('created_at', [$monthStart, $monthEnd]))->count();
        $runningClasses = $inBranches(ClassModel::query()->where('status', 'active'))->count();

        $overdueTasks = WorkTask::query()
            ->whereNotIn('status', ['completed', 'canceled'])
            ->where(fn (Builder $q) => $q->where('status', 'overdue')->orWhereDate('due_date', '<', today()))
            ->when($branchIds !== null, fn (Builder $q) => $q->where(fn (Builder $b) => $b
                ->whereIn('branch_id', $branchIds)
                ->orWhereHas('assignee', fn (Builder $a) => $a->whereIn('branch_id', $branchIds))))
            ->count();

        $overdueList = WorkTask::query()
            ->with('assignee:id,name')
            ->whereNotIn('status', ['completed', 'canceled'])
            ->where(fn (Builder $q) => $q->where('status', 'overdue')->orWhereDate('due_date', '<', today()))
            ->when($branchIds !== null, fn (Builder $q) => $q->where(fn (Builder $b) => $b
                ->whereIn('branch_id', $branchIds)
                ->orWhereHas('assignee', fn (Builder $a) => $a->whereIn('branch_id', $branchIds))))
            ->orderBy('due_date')
            ->limit(5)
            ->get();

        return [
            'type' => $branchIds === null ? 'admin' : 'manager',
            'title' => $branchIds === null ? 'Tổng quan toàn hệ thống' : 'Tổng quan chi nhánh',
            'scope' => $scopeLabel,
            'stats' => [
                ['label' => 'Doanh thu tháng '.now()->format('m/Y'), 'value' => number_format((float) $revenue, 0, ',', '.').'đ', 'icon' => 'payments', 'tone' => 'success', 'hint' => 'Tổng phiếu thu đã duyệt'],
                ['label' => 'Học viên đang học', 'value' => number_format($studying), 'icon' => 'school', 'tone' => 'primary', 'hint' => null],
                ['label' => 'Lead mới trong tháng', 'value' => number_format($newLeads), 'icon' => 'person_add', 'tone' => 'secondary', 'hint' => null],
                ['label' => 'Lớp đang chạy', 'value' => number_format($runningClasses), 'icon' => 'co_present', 'tone' => 'default', 'hint' => null],
                ['label' => 'Việc quá hạn', 'value' => number_format($overdueTasks), 'icon' => 'alarm', 'tone' => $overdueTasks > 0 ? 'error' : 'default', 'hint' => null],
            ],
            'overdueTasks' => $overdueList,
        ];
    }

    private function academicDashboard(User $user): array
    {
        $runningClasses = ClassModel::query()->visibleTo($user)->where('status', 'active')->count();
        $pendingAdjustments = SyllabusAdjustmentRequest::query()
            ->with(['classModel:id,name', 'teacher:id,name'])
            ->where('status', 'pending')
            ->latest()
            ->get();
        $upcomingBigTests = BigTest::query()
            ->with('classModel:id,name')
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [now()->startOfDay(), now()->addDays(14)->endOfDay()])
            ->orderBy('scheduled_at')
            ->limit(8)
            ->get();

        return [
            'type' => 'academic',
            'title' => 'Tổng quan học thuật',
            'scope' => 'Học thuật',
            'stats' => [
                ['label' => 'Lớp đang chạy', 'value' => number_format($runningClasses), 'icon' => 'co_present', 'tone' => 'primary', 'hint' => null],
                ['label' => 'Đề xuất giáo trình / giãn tiến độ chờ duyệt', 'value' => number_format($pendingAdjustments->count()), 'icon' => 'pending_actions', 'tone' => $pendingAdjustments->isNotEmpty() ? 'warning' : 'default', 'hint' => null],
                ['label' => 'Big Test trong 14 ngày tới', 'value' => number_format($upcomingBigTests->count()), 'icon' => 'quiz', 'tone' => 'secondary', 'hint' => null],
            ],
            'pendingAdjustments' => $pendingAdjustments->take(5),
            'upcomingBigTests' => $upcomingBigTests,
        ];
    }
}
