<?php

namespace App\Http\Controllers;

use App\Helpers\AclHelper;
use App\Models\BigTest;
use App\Models\BigTestOrder;
use App\Models\BigTestResult;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\CrmCustomer;
use App\Models\StaffReport;
use App\Models\Student;
use App\Models\SupportTicket;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\SyllabusChangeProposal;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Models\WorkTask;
use App\Support\Approvals\ApprovalInboxService;
use App\Support\Dashboard\MyWorkBoard;
use App\Support\Dashboard\TeachingQuality;
use App\Support\DataScope;
use App\Support\Money;
use App\Support\Navigation\SidebarMenu;
use App\Support\ReportPeriod;
use App\Support\Roles;
use App\Support\StaffType;
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
 *  - dashboard.academic (Học thuật): lớp đang chạy, đề xuất giáo trình/giãn tiến độ chờ duyệt, Big Test sắp tới,
 *    kèm lịch hẹn 7 ngày tới + việc của tôi (MyWorkBoard).
 *  - Người khác: "Việc của bạn" (MyWorkBoard) — lịch hẹn 7 ngày tới, đầu việc cần xử lý, việc được giao trong tuần.
 *  - Chất lượng giảng dạy theo tháng (teaching()): giáo viên xem của mình, người theo dõi giáo viên xem theo giáo viên.
 * Lưới phân hệ (phím tắt) cuối trang đã bỏ (04/10/2026): menu trái đã có đủ.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, SidebarMenu $menu, ApprovalInboxService $approvals): Response|RedirectResponse
    {
        $user = $request->user();

        // Học viên / phụ huynh: Tổng quan là trang của nhân sự → vào thẳng Trang chủ cổng học viên.
        if ($user->isPortalStudentOnly()) {
            return redirect()->route('portal.student.home');
        }
        $isOperations = $user->can('dashboard.operations');

        // Khối số liệu & ô phân hệ chỉ hiện link user mở được: quyền đọc từ middleware `can:` của route như menu trái
        // (SidebarMenu::canSee). `can` bổ sung cho route tự kiểm tra quyền trong controller.
        $canOpen = fn (string $route, array $can = []) => $menu->canSee($user, ['route' => $route, 'can' => $can], $request);
        // Admin / Quản lý: bảng điều hành nhiều số liệu. Vai trò khác (chủ dự án 03/10/2026): lịch hẹn + đầu việc cần xử lý
        // trong tuần / tháng của chính mình; Học thuật giữ hàng chờ duyệt chuyên môn và thêm lịch hẹn + việc của tôi.
        $myWork = new MyWorkBoard($user, $canOpen, $approvals);

        $roleDashboard = match (true) {
            $isOperations => DataScope::isAll($user, 'dashboard')
                ? $this->operationsDashboard($user, null, 'Toàn hệ thống')
                : $this->operationsDashboard($user, $user->branchIds(), $user->branch?->name ?? 'Chi nhánh của bạn'),
            $user->can('dashboard.academic') => $this->academicDashboard($user) + $myWork->agendaAndTasks(),
            default => $myWork->build(),
        };

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
            'teaching' => $this->teaching($user, $request->input('month'), $canOpen),
        ]);
    }

    /**
     * Chất lượng giảng dạy theo tháng (chủ dự án 04/10/2026), chia theo vai trò:
     *  - Giáo viên / trợ giảng: số liệu của chính mình (ngày công, đi muộn, phép, vi phạm) + từng lớp đang giữ.
     *  - Người theo dõi giáo viên (kpi.view, bảng điều hành, Học thuật): bảng theo giáo viên trong phạm vi lớp mình thấy.
     *  - Học thuật / Admin / Quản lý: thêm báo cáo tháng của giáo viên (tiến độ, khó khăn, đề xuất, lớp cần hỗ trợ) và
     *    order học thuật trong tháng.
     *
     * @param  Closure(string, array<int, string>=): bool  $canOpen
     * @return array<string, mixed>|null
     */
    private function teaching(User $user, ?string $month, Closure $canOpen): ?array
    {
        $isTeaching = $user->hasAnyRole(Roles::TEACHING);
        $watchesTeachers = $user->can('kpi.view') || $user->can('dashboard.operations') || $user->can('dashboard.academic');
        if (! $isTeaching && ! $watchesTeachers) {
            return null;
        }

        $month = $month && preg_match(ReportPeriod::MONTH_PATTERN, $month) ? $month : ReportPeriod::currentMonth();
        $quality = new TeachingQuality($month);
        $team = $watchesTeachers ? $quality->teachersTable($user) : null;
        $readsReports = $team !== null && ($user->can('dashboard.academic') || $user->can('dashboard.operations'));

        return [
            'month' => $quality->monthKey(),
            'monthLabel' => $quality->monthLabel(),
            'months' => ReportPeriod::monthOptions(),
            'mine' => $isTeaching ? $quality->forStaff($user) : null,
            'reportUrl' => $isTeaching && in_array('teacher_monthly', StaffType::structuredReports($user), true)
                ? route('reports.periodic.teacher-monthly', ['month' => $quality->monthKey()]) : null,
            'team' => $team,
            'teacherReports' => $readsReports ? $this->teacherReports(collect($team['rows'])->pluck('name', 'id')->all(), $quality) : null,
            'academicOrders' => $readsReports
                ? StaffReportController::academicOrders(collect($team['rows'])->pluck('id')->all(), $quality, 10) : null,
            'ordersUrl' => $canOpen('material-orders.index') ? route('material-orders.index') : null,
        ];
    }

    /**
     * Báo cáo tháng của từng giáo viên (StaffReportController::teacherMonthly): phần chung + lớp cần hỗ trợ; chưa nộp vẫn liệt kê.
     *
     * @param  array<int, string>  $teachers  id => tên
     * @return list<array<string, mixed>>
     */
    private function teacherReports(array $teachers, TeachingQuality $quality): array
    {
        $reports = StaffReport::query()->whereIn('user_id', array_keys($teachers))->where('type', 'monthly')
            ->where('period_key', $quality->monthKey())->get()->keyBy('user_id');
        $classNames = ClassModel::query()
            ->whereIn('id', $reports->flatMap(fn (StaffReport $r) => array_keys($r->data['classes'] ?? []))->unique()->all())
            ->pluck('name', 'id');

        return collect($teachers)->map(function (string $name, int $id) use ($reports, $classNames) {
            $report = $reports->get($id);
            $general = $report?->data['general'] ?? [];

            return [
                'id' => $id,
                'teacher' => $name,
                'submitted' => $report !== null,
                'updated_at' => $report?->updated_at?->toIso8601String(),
                'progress' => $general['progress'] ?? null,
                'difficulties' => $general['difficulties'] ?? null,
                'proposals' => $general['proposals'] ?? null,
                'classes' => collect($report?->data['classes'] ?? [])->map(fn (array $row, $classId) => [
                    'class' => $classNames[(int) $classId] ?? 'Lớp',
                    'attention' => $row['attention'] ?? null,
                    'solution' => $row['solution'] ?? null,
                    'need_support' => (bool) ($row['need_support'] ?? false),
                    'support_note' => $row['support_note'] ?? null,
                ])->values()->all(),
            ];
        })->sortBy([['submitted', 'desc'], ['teacher', 'asc']])->values()->all();
    }

    /**
     * Lịch hẹn test đầu vào hôm nay (CRM, theo phạm vi khách truyền vào): tổng, số chưa làm bài, link danh sách lọc sẵn.
     *
     * @return array{url: string, count: int, pending: int}
     */
    private function testTodaySummary(Builder $customers): array
    {
        $today = (clone $customers)->testToday();

        return [
            'url' => route('crm.customers.index', ['test_today' => 1]),
            'count' => (clone $today)->count(),
            'pending' => (clone $today)->where('stage', 'test_scheduled')->count(),
        ];
    }

    /**
     * @param  int[]|null  $branchIds  null = toàn hệ thống
     */
    private function operationsDashboard(User $user, ?array $branchIds, string $scopeLabel): array
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

        // Nhiệm vụ hằng ngày của TA (CV-05) không có hạn → không tính quá hạn.
        $overdueTasks = WorkTask::query()
            ->withDeadline()
            ->whereNotIn('status', ['completed', 'canceled'])
            ->where(fn (Builder $q) => $q->where('status', 'overdue')->orWhereDate('due_date', '<', today()))
            ->when($branchIds !== null, fn (Builder $q) => $q->where(fn (Builder $b) => $b
                ->whereIn('branch_id', $branchIds)
                ->orWhereHas('assignee', fn (Builder $a) => $a->whereIn('branch_id', $branchIds))))
            ->count();

        $overdueList = WorkTask::query()
            ->with('assignee:id,name')
            ->withDeadline()
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

        // Lịch hẹn test hôm nay: cùng phạm vi khách CRM với danh sách mà link mở ra.
        $testToday = $user->can('lead.view') ? $this->testTodaySummary(CrmCustomer::query()->visibleTo($user)) : null;

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
                ...($testToday ? [['label' => 'Lịch hẹn test hôm nay', 'value' => $testToday['count'], 'icon' => 'event', 'href' => $testToday['url'], 'hint' => $testToday['count'] > 0 ? "{$testToday['pending']} chưa làm bài" : null]] : []),
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
