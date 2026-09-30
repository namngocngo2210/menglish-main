<?php

namespace App\Http\Controllers;

use App\Helpers\AclHelper;
use App\Models\BigTest;
use App\Models\BigTestOrder;
use App\Models\BigTestResult;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\CrmCustomer;
use App\Models\PayrollPeriod;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\SupportTicket;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\SyllabusChangeProposal;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Models\WorkTask;
use App\Support\DataScope;
use App\Support\Money;
use App\Support\Navigation\SidebarMenu;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard theo quyền (BPMN bước 22):
 *  - dashboard.operations: bảng điều hành — phạm vi "dashboard.scope_all" (Admin) số liệu toàn hệ thống, mức
 *    "Chi nhánh" (Quản lý cơ sở) giới hạn chi nhánh mình.
 *  - dashboard.academic (Học thuật): lớp đang chạy, đề xuất giáo trình/giãn tiến độ chờ duyệt, Big Test sắp tới.
 * Người khác giữ lưới lối tắt theo quyền như trước (thẻ số liệu + ô phân hệ, chỉ gồm link user mở được).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, SidebarMenu $menu): Response|RedirectResponse
    {
        $user = $request->user();

        // Học viên / phụ huynh: Tổng quan là trang của nhân sự → vào thẳng Trang chủ cổng học viên.
        if ($user->isPortalStudentOnly()) {
            return redirect()->route('portal.student.home');
        }
        $roleDashboard = null;
        $isOperations = $user->can('dashboard.operations');

        if ($isOperations) {
            $roleDashboard = DataScope::isAll($user, 'dashboard')
                ? $this->operationsDashboard(null, 'Toàn hệ thống')
                : $this->operationsDashboard($user->branchIds(), $user->branch?->name ?? 'Chi nhánh của bạn');
        } elseif ($user->can('dashboard.academic')) {
            $roleDashboard = $this->academicDashboard($user);
        }

        // Thẻ số liệu & ô phân hệ chỉ hiện link user mở được: quyền đọc từ middleware `can:` của route như menu trái
        // (SidebarMenu::canSee). `can` bổ sung cho route tự kiểm tra quyền trong controller.
        $canOpen = fn (string $route, array $can = []) => $menu->canSee($user, ['route' => $route, 'can' => $can], $request);

        return Inertia::render('Dashboard', [
            'isOperations' => $isOperations,
            // Lời chào cho nhân sự / giáo viên (bảng điều hành Admin / Quản lý không có).
            'welcome' => $isOperations ? null : [
                'name' => $user->name,
                'roles' => $user->getRoleNames()->map(fn ($r) => AclHelper::shortRoleLabel($r))->implode(', ') ?: 'Nhân viên',
                'branch' => $user->branch?->name ?? 'Trung tâm',
                'salaryUrl' => $user->can('payroll.view') || $user->can('payroll.view_own') ? route('portal.my-salary') : null,
                'tasksUrl' => $user->can('work_task.view') ? route('portal.ta-tasks') : null,
            ],
            'roleDashboard' => $roleDashboard,
            'kpis' => $roleDashboard === null ? $this->kpiCards($user, $canOpen) : null,
            'modules' => $this->modules($user, $canOpen),
        ]);
    }

    /**
     * Thẻ số liệu cho vai trò không có dashboard riêng — số liệu theo phạm vi dữ liệu của user (chi nhánh / lớp mình),
     * không phải toàn trung tâm. Thẻ nào user không mở được màn đích thì null.
     *
     * @param  Closure(string, array<int, string>=): bool  $canOpen
     * @return array<string, array<string, mixed>|null>
     */
    private function kpiCards(User $user, Closure $canOpen): array
    {
        $cards = ['lead' => null, 'tuition' => null, 'student' => null, 'payroll' => null];

        if ($user->can('lead.view') && $canOpen('crm.pipeline')) {
            $cards['lead'] = [
                'url' => route('crm.pipeline'),
                'count' => CrmCustomer::query()->visibleTo($user)->count(),
                'won' => CrmCustomer::query()->visibleTo($user)->where('stage', 'won')->count(),
            ];
        }
        if ($user->can('tuition.view') && $canOpen('tuition.students')) {
            $tuitionScope = fn () => StudentTuition::query()->whereHas('student', fn ($q) => $q->visibleTo($user));
            $cards['tuition'] = [
                'url' => route('tuition.students'),
                'paid' => number_format($tuitionScope()->sum('paid_amount') / 1000000, 1).' tr',
                'overdue' => $tuitionScope()->where('status', 'overdue')->count(),
            ];
        }
        if ($user->can('student.view') && $canOpen('students.index')) {
            $cards['student'] = [
                'url' => route('students.index'),
                'students' => Student::query()->visibleTo($user)->count(),
                'classes' => ClassModel::query()->visibleTo($user)->where('status', 'active')->count(),
            ];
        }
        $payrollRoute = $user->can('payroll.view') ? 'payroll.periods.index' : 'portal.my-salary';
        if (($user->can('payroll.view') || $user->can('payroll.view_own')) && $canOpen($payrollRoute)) {
            $latestPayroll = PayrollPeriod::latest()->first();
            $cards['payroll'] = [
                'url' => route($payrollRoute),
                'value' => $user->can('payroll.view') ? number_format(($latestPayroll?->total_amount ?? 0) / 1000000, 1).' tr' : 'Xem phiếu',
                'title' => $latestPayroll?->title ?? 'Chưa có kỳ lương',
            ];
        }

        return $cards;
    }

    /**
     * Ô "Các phân hệ chức năng của bạn": chỉ link user mở được; ô không còn link nào thì ẩn.
     *
     * @param  Closure(string, array<int, string>=): bool  $canOpen
     * @return list<array<string, mixed>>
     */
    private function modules(User $user, Closure $canOpen): array
    {
        $linkTo = fn (string $label, string $route, array $params = [], array $can = []) => $canOpen($route, $can)
            ? ['label' => $label, 'url' => route($route, $params)]
            : null;

        // [bật?, tiêu đề, mô tả, icon, lớp icon, lớp viền hover, lớp link hover, links]
        return collect([
            [$user->can('lead.view'), 'CRM & Tuyển sinh', 'Quản lý khách hàng tiềm năng', 'pie_chart', 'bg-primary-container/10 text-primary', 'hover:border-primary-container/50', 'hover:bg-primary-container/10 hover:text-primary', fn () => [
                $linkTo('Pipeline Kanban', 'crm.pipeline'),
                $linkTo('DS Khách hàng', 'crm.customers.index'),
                $linkTo('Chốt & Xếp lớp', 'crm.closing-wizard', [], ['class.update']),
                $linkTo('Báo cáo Doanh số', 'crm.reports'),
            ]],
            [$user->can('tuition.view'), 'Học phí & Hóa đơn', 'Thu phí và quản lý công nợ', 'receipt_long', 'bg-warning-container text-warning', 'hover:border-warning/50', 'hover:bg-warning-container hover:text-on-warning-container', fn () => [
                $linkTo('DS Thu phí', 'tuition.students'),
                $linkTo('Lập Phiếu thu', 'tuition.receipts.create'),
                $linkTo('Duyệt Phiếu thu', 'tuition.receipts.approve'),
                $linkTo('Thu quá hạn', 'tuition.overdue'),
            ]],
            [$user->can('student.view'), 'Hồ sơ Học sinh', 'Quản lý thông tin học viên', 'school', 'bg-tertiary/10 text-tertiary', 'hover:border-tertiary/50', 'hover:bg-tertiary/10 hover:text-tertiary', fn () => [
                $linkTo('DS & Liên kết lớp', 'students.index'),
                $linkTo('Chờ khai giảng', 'students.index', ['status' => 'waiting_start']),
                $linkTo('Đang học', 'students.index', ['status' => 'studying']),
                $linkTo('Xác nhận nhập học', 'students.enrollments'),
            ]],
            [$user->can('class.view'), 'Lớp học & Lịch dạy', 'Lịch học, điểm danh & TKB', 'meeting_room', 'bg-secondary/10 text-secondary', 'hover:border-secondary/50', 'hover:bg-secondary/10 hover:text-secondary', fn () => [
                $linkTo('Lịch học các lớp', 'tasks.classes-dashboard'),
                $linkTo('Lịch & TKB lớp', 'tasks.schedule-config'),
                $linkTo('Lịch dạy GV', 'payroll.timesheets.teachers', [], ['attendance_staff.view', 'payroll.view_own']),
            ]],
            [$user->can('work_task.view'), 'Phân công & Trợ giảng', 'Công việc ca trực & báo cáo', 'task_alt', 'bg-primary-container/10 text-primary', 'hover:border-primary-container/50', 'hover:bg-primary-container/10 hover:text-primary', fn () => [
                $linkTo('Danh sách việc', 'tasks.index'),
                $linkTo('Nhiệm vụ hôm nay', 'portal.ta-tasks'),
                $linkTo('Báo cáo trực lớp', 'tasks.class-reports.create'),
            ]],
            [$user->can('syllabus.manage'), 'Syllabus & Giáo trình', 'Soạn giáo trình & Big Test', 'auto_stories', 'bg-accent-container text-accent', 'hover:border-accent/50', 'hover:bg-accent-container hover:text-accent', fn () => [
                $linkTo('Giáo trình tài liệu', 'syllabus.documents'),
                $linkTo('Soạn Syllabus', 'syllabus.builder'),
                $linkTo('Phân phối Big Test', 'syllabus.big-tests.distribution'),
            ]],
            [$user->can('user.view'), 'Quản trị Hệ thống', 'Tài khoản & Phân quyền', 'settings', 'bg-info-container text-info', 'hover:border-info/50', 'hover:bg-info-container hover:text-info', fn () => [
                $linkTo('Tài khoản', 'users.index'),
                $linkTo('Vai trò (Roles)', 'roles.index'),
                $linkTo('Permissions', 'permissions.index'),
                $linkTo('Nhật ký vận hành', 'activity-logs.index'),
            ]],
        ])
            ->filter(fn (array $module) => $module[0])
            ->map(fn (array $module) => [
                'title' => $module[1],
                'subtitle' => $module[2],
                'icon' => $module[3],
                'iconClass' => $module[4],
                'borderClass' => $module[5],
                'linkClass' => $module[6],
                'links' => array_values(array_filter(($module[7])())),
            ])
            ->filter(fn (array $module) => $module['links'] !== [])
            ->values()
            ->all();
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

        // Hàng chờ xử lý (BPMN 22): việc chờ xác nhận, báo cáo trực lớp chờ xác nhận (Q8),
        // phiếu thu chờ duyệt, ticket chưa xử lý — đều trong phạm vi chi nhánh.
        $pendingTasks = WorkTask::query()->where('status', 'pending_confirmation')
            ->when($branchIds !== null, fn (Builder $q) => $q->where(fn (Builder $b) => $b
                ->whereIn('branch_id', $branchIds)
                ->orWhereHas('assignee', fn (Builder $a) => $a->whereIn('branch_id', $branchIds))))
            ->count();
        $pendingReports = ClassReport::query()->where('status', ClassReport::STATUS_PENDING)
            ->when($branchIds !== null, fn (Builder $q) => $q->whereHas('classModel', fn (Builder $c) => $c->whereIn('branch_id', $branchIds)))
            ->count();
        $pendingReceipts = TuitionReceipt::query()->where('status', TuitionReceipt::STATUS_PENDING)
            ->when($branchIds !== null, fn (Builder $q) => $q->whereHas('student', fn (Builder $s) => $s->whereIn('branch_id', $branchIds)))
            ->count();
        $openTickets = SupportTicket::query()->whereIn('status', ['open', 'in_progress'])
            ->when($branchIds !== null, fn (Builder $q) => $q->whereHas('creator', fn (Builder $c) => $c->whereIn('branch_id', $branchIds)))
            ->count();
        $unassignedTickets = SupportTicket::query()->where('status', 'open')->whereNull('assignee_id')
            ->when($branchIds !== null, fn (Builder $q) => $q->whereHas('creator', fn (Builder $c) => $c->whereIn('branch_id', $branchIds)))
            ->count();

        return [
            'type' => $branchIds === null ? 'admin' : 'manager',
            'title' => $branchIds === null ? 'Tổng quan toàn hệ thống' : 'Tổng quan chi nhánh',
            'scope' => $scopeLabel,
            'updatedAt' => now()->format('H:i d/m/Y'),
            'stats' => [
                ['label' => 'Doanh thu tháng '.now()->format('m/Y'), 'value' => Money::format((float) $revenue), 'icon' => 'payments', 'tone' => 'success', 'hint' => 'Tổng phiếu thu đã duyệt'],
                ['label' => 'Học viên đang học', 'value' => number_format($studying), 'icon' => 'school', 'tone' => 'primary', 'hint' => null],
                ['label' => 'Lead mới trong tháng', 'value' => number_format($newLeads), 'icon' => 'person_add', 'tone' => 'secondary', 'hint' => null],
                ['label' => 'Lớp đang chạy', 'value' => number_format($runningClasses), 'icon' => 'co_present', 'tone' => 'default', 'hint' => null],
                ['label' => 'Việc quá hạn', 'value' => number_format($overdueTasks), 'icon' => 'alarm', 'tone' => $overdueTasks > 0 ? 'error' : 'default', 'hint' => null],
            ],
            'queues' => [
                ['label' => 'Việc chờ xác nhận', 'value' => $pendingTasks, 'icon' => 'pending_actions', 'href' => route('tasks.manual-approvals')],
                ['label' => 'Báo cáo trực lớp chờ xác nhận', 'value' => $pendingReports, 'icon' => 'fact_check', 'href' => route('tasks.manual-approvals', ['kind' => 'report']), 'hint' => 'GV chính / người giao việc xác nhận'],
                ['label' => 'Phiếu thu chờ duyệt', 'value' => $pendingReceipts, 'icon' => 'receipt_long', 'href' => route('tuition.receipts.approve')],
                ['label' => 'Ticket đang mở', 'value' => $openTickets, 'icon' => 'support_agent', 'href' => route('tickets.index'), 'hint' => $unassignedTickets > 0 ? "{$unassignedTickets} ticket chưa có người xử lý" : null],
            ],
            'overdueTasks' => $overdueList->map(fn (WorkTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'assignee' => $task->assignee?->name,
                'due_date' => $task->due_date?->toDateString(),
            ])->all(),
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
        $pendingProposals = SyllabusChangeProposal::query()->where('status', 'pending')->count();
        $pendingOrders = BigTestOrder::query()->where('status', 'pending')->count();
        $pendingResults = BigTestResult::query()->where('status', 'pending_review')->distinct('big_test_id')->count('big_test_id');
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
            'updatedAt' => now()->format('H:i d/m/Y'),
            'stats' => [
                ['label' => 'Lớp đang chạy', 'value' => number_format($runningClasses), 'icon' => 'co_present', 'tone' => 'primary', 'hint' => null],
                ['label' => 'Đề xuất giáo trình / giãn tiến độ chờ duyệt', 'value' => number_format($pendingAdjustments->count() + $pendingProposals), 'icon' => 'pending_actions', 'tone' => ($pendingAdjustments->count() + $pendingProposals) > 0 ? 'warning' : 'default', 'hint' => "{$pendingProposals} đề xuất sửa giáo trình · {$pendingAdjustments->count()} giãn tiến độ"],
                ['label' => 'Big Test trong 14 ngày tới', 'value' => number_format($upcomingBigTests->count()), 'icon' => 'quiz', 'tone' => 'secondary', 'hint' => null],
            ],
            'queues' => [
                ['label' => 'Đề xuất sửa giáo trình', 'value' => $pendingProposals, 'icon' => 'edit_note', 'href' => route('syllabus.versions', ['status' => 'pending'])],
                ['label' => 'Order đề Big Test chờ duyệt', 'value' => $pendingOrders, 'icon' => 'assignment', 'href' => route('syllabus.big-tests.distribution')],
                ['label' => 'Đợt Big Test có kết quả chờ duyệt', 'value' => $pendingResults, 'icon' => 'grading', 'href' => route('syllabus.big-tests.results')],
            ],
            'pendingAdjustments' => $pendingAdjustments->take(5)->map(fn (SyllabusAdjustmentRequest $req) => [
                'id' => $req->id,
                'class' => $req->classModel?->name,
                'teacher' => $req->teacher?->name,
                'reason' => $req->reason,
            ])->values()->all(),
            'upcomingBigTests' => $upcomingBigTests->map(fn (BigTest $test) => [
                'id' => $test->id,
                'title' => $test->title ?? $test->code,
                'class' => $test->classModel?->name,
                'scheduled_at' => $test->scheduled_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
