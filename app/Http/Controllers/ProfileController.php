<?php

namespace App\Http\Controllers;

use App\Helpers\AclHelper;
use App\Http\Requests\ProfileUpdateRequest;
use App\Models\ClassModel;
use App\Models\CommissionItem;
use App\Models\CrmCustomer;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\StaffReport;
use App\Models\SupportTicket;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\PayrollFormulaService;
use App\Services\SalesCommissionService;
use App\Support\Approvals\ApprovalInboxService;
use App\Support\Money;
use App\Support\Navigation\SidebarMenu;
use App\Support\StaffType;
use App\Support\StatusLabel;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ProfileController extends Controller
{
    /** Kỳ báo cáo định kỳ (StaffType::reportCadence) → nhãn thẻ số liệu. */
    private const REPORT_LABELS = [
        'daily' => ['Báo cáo ngày', 'Hôm nay'],
        'weekly' => ['Báo cáo tuần', 'Tuần này'],
        'monthly' => ['Báo cáo tháng', 'Tháng này'],
    ];

    /**
     * Lối tắt "Màn hình công việc của bạn" theo cổng. Mỗi mục chỉ hiện khi người dùng mở được (cùng luật menu trái:
     * SidebarMenu::canSee), nên người kiêm nhiệm / bị Admin chỉnh quyền vẫn chỉ thấy màn mình vào được.
     */
    private const QUICK_LINKS = [
        'teacher' => [
            ['label' => 'Chấm công & xin duyệt (điện thoại)', 'route' => 'mobile.home', 'icon' => 'fingerprint'],
            ['label' => 'Check-in & Điểm danh hôm nay', 'route' => 'teacher.home', 'icon' => 'how_to_reg', 'can' => ['attendance_student.record']],
            ['label' => 'Nhiệm vụ hôm nay', 'route' => 'portal.ta-tasks', 'icon' => 'checklist'],
            ['label' => 'Chấm bài nộp của lớp', 'route' => 'portal.teacher.submissions', 'icon' => 'grading'],
            ['label' => 'Chặng đang dạy & Order Test', 'route' => 'syllabus.teaching-stages', 'icon' => 'menu_book'],
            ['label' => 'Khách học thử', 'route' => 'teacher.trial-guests', 'icon' => 'person_search'],
            ['label' => 'Xin duyệt giáo trình / tiến độ', 'route' => 'syllabus.teacher-propose', 'icon' => 'rate_review'],
            ['label' => 'Báo cáo định kỳ của tôi', 'route' => 'reports.my', 'icon' => 'summarize'],
            ['label' => 'Lương của tôi', 'route' => 'portal.my-salary', 'icon' => 'payments', 'can' => ['payroll.view_own']],
        ],
        'assistant' => [
            ['label' => 'Chấm công & xin duyệt (điện thoại)', 'route' => 'mobile.home', 'icon' => 'fingerprint'],
            ['label' => 'Nhiệm vụ hôm nay', 'route' => 'portal.ta-tasks', 'icon' => 'checklist'],
            ['label' => 'Check-in & Điểm danh hôm nay', 'route' => 'teacher.home', 'icon' => 'how_to_reg', 'can' => ['attendance_student.record']],
            ['label' => 'Chấm bài nộp của lớp', 'route' => 'portal.teacher.submissions', 'icon' => 'grading'],
            ['label' => 'Báo cáo định kỳ của tôi', 'route' => 'reports.my', 'icon' => 'summarize'],
            ['label' => 'Lương của tôi', 'route' => 'portal.my-salary', 'icon' => 'payments', 'can' => ['payroll.view_own']],
        ],
        'academic_staff' => [
            ['label' => 'Chấm công & xin duyệt (điện thoại)', 'route' => 'mobile.home', 'icon' => 'fingerprint'],
            ['label' => 'Khách hàng (CRM)', 'route' => 'crm.pipeline', 'icon' => 'contacts'],
            ['label' => 'Học viên', 'route' => 'students.index', 'icon' => 'school'],
            ['label' => 'Lớp học', 'route' => 'classes.index', 'icon' => 'meeting_room'],
            ['label' => 'Lịch học các lớp', 'route' => 'tasks.classes-dashboard', 'icon' => 'calendar_month'],
            ['label' => 'Công việc', 'route' => 'tasks.index', 'icon' => 'task_alt'],
            ['label' => 'Việc cần duyệt', 'route' => 'approvals.index', 'icon' => 'approval', 'can' => [\App\Providers\ApprovalServiceProvider::INBOX_ABILITY]],
            ['label' => 'Nhật ký sự vụ học vụ', 'route' => 'reports.journal', 'icon' => 'edit_note'],
            ['label' => 'Báo cáo ngày của tôi', 'route' => 'reports.my', 'icon' => 'summarize'],
            ['label' => 'Lương của tôi', 'route' => 'portal.my-salary', 'icon' => 'payments', 'can' => ['payroll.view_own']],
        ],
        'academic_lead' => [
            ['label' => 'Chấm công & xin duyệt (điện thoại)', 'route' => 'mobile.home', 'icon' => 'fingerprint'],
            ['label' => 'Tổng quan Học thuật', 'route' => 'dashboard', 'icon' => 'dashboard'],
            ['label' => 'Việc cần duyệt', 'route' => 'approvals.index', 'icon' => 'approval', 'can' => [\App\Providers\ApprovalServiceProvider::INBOX_ABILITY]],
            ['label' => 'Giáo trình', 'route' => 'syllabus.documents', 'icon' => 'menu_book'],
            ['label' => 'Big Test: Bảng điểm & Kết quả', 'route' => 'syllabus.big-tests.results', 'icon' => 'quiz'],
            ['label' => 'Lớp học', 'route' => 'classes.index', 'icon' => 'meeting_room'],
            ['label' => 'Báo cáo & sự vụ lớp', 'route' => 'academic.dashboards.reports', 'icon' => 'monitoring'],
            ['label' => 'Công việc', 'route' => 'tasks.index', 'icon' => 'task_alt'],
            ['label' => 'Báo cáo tuần của tôi', 'route' => 'reports.my', 'icon' => 'summarize'],
            ['label' => 'Lương của tôi', 'route' => 'portal.my-salary', 'icon' => 'payments', 'can' => ['payroll.view_own']],
        ],
        'staff' => [
            ['label' => 'Chấm công & xin duyệt (điện thoại)', 'route' => 'mobile.home', 'icon' => 'fingerprint'],
            ['label' => 'Tổng quan', 'route' => 'dashboard', 'icon' => 'dashboard'],
            ['label' => 'Việc cần duyệt', 'route' => 'approvals.index', 'icon' => 'approval', 'can' => [\App\Providers\ApprovalServiceProvider::INBOX_ABILITY]],
            ['label' => 'Công việc', 'route' => 'tasks.index', 'icon' => 'task_alt', 'can' => ['work_task.create']],
            ['label' => 'Báo cáo định kỳ của tôi', 'route' => 'reports.my', 'icon' => 'summarize'],
            ['label' => 'Lương của tôi', 'route' => 'portal.my-salary', 'icon' => 'payments', 'can' => ['payroll.view_own']],
        ],
        'student' => [
            ['label' => 'Trang chủ học viên', 'route' => 'portal.student.home', 'icon' => 'cottage'],
            ['label' => 'Học tập & Nộp bài tập', 'route' => 'portal.student.homework', 'icon' => 'menu_book'],
            ['label' => 'Luyện phát âm', 'route' => 'portal.student.pronunciation', 'icon' => 'mic'],
            ['label' => 'Hộp thư thông báo', 'route' => 'portal.student.notifications', 'icon' => 'notifications'],
            ['label' => 'Khảo sát', 'route' => 'portal.student.survey', 'icon' => 'assignment'],
        ],
    ];

    /**
     * Trang cá nhân ("cổng" của từng người): thẻ số liệu, tab và lối tắt theo công việc thực của vai trò.
     * GV / TA: giờ dạy, lớp, ca dạy; Học vụ: việc, hoa hồng tạm tính, báo cáo ngày, việc cần duyệt; Học thuật: đề xuất chờ duyệt, báo cáo tuần;
     * Học viên: chỉ tài khoản & mật khẩu + lối về cổng học viên (không thấy lương / chấm công / ticket nội bộ).
     */
    public function edit(Request $request): InertiaResponse
    {
        $user = $request->user();
        $portal = $this->portalFor($user);

        if ($portal === 'student') {
            return Inertia::render('Profile/Edit', [
                ...$this->accountProps($request, $user, showOperations: false),
                'portal' => $portal,
                'roleLabels' => $this->roleLabels($user),
                'quickLinks' => $this->quickLinks($user, $portal, $request),
                'statCards' => [],
                'showPayroll' => false,
                'showTickets' => false,
                'showOperations' => false,
            ]);
        }

        $teaches = in_array($portal, ['teacher', 'assistant'], true) || $user->can('portal.teacher') || $user->can('portal.assistant');

        // Lương: chỉ phiếu của kỳ đã chốt — cùng nguồn với "Lương của tôi", không lộ số liệu bảng lương đang tính (nháp).
        $lockedPayrolls = PayrollRecord::with('period')
            ->where('user_id', $user->id)
            ->whereHas('period', fn ($q) => $q->whereIn('status', PayrollPeriod::LOCKED_STATUSES))
            ->latest()
            ->take(6)
            ->get();
        $latestPayroll = $lockedPayrolls->first();
        $recentPayrolls = $lockedPayrolls;
        $showPayroll = $user->can('payroll.view_own') || $latestPayroll !== null;

        // Giờ dạy / ca dạy: chỉ người đứng lớp (GV, TA).
        $monthlyTimesheets = collect();
        $recentTimesheets = collect();
        $assignedClasses = collect();
        $activeClassCount = 0;
        if ($teaches) {
            $monthlyTimesheets = TeacherTimesheet::with('classModel')
                ->where('user_id', $user->id)
                ->whereMonth('teaching_date', now()->month)
                ->whereYear('teaching_date', now()->year)
                ->get();
            $recentTimesheets = TeacherTimesheet::with('classModel')
                ->where('user_id', $user->id)
                ->latest('teaching_date')
                ->take(5)
                ->get();
            $classQuery = fn () => ClassModel::query()->where(fn ($q) => $q
                ->where('teacher_id', $user->id)
                ->orWhere('assistant_id', $user->id)
                ->orWhere('foreign_teacher_id', $user->id));
            $assignedClasses = $classQuery()->with(['course', 'branch'])->latest()->take(4)->get();
            $activeClassCount = $classQuery()->where('status', 'active')->count();
        }
        $totalMonthlyHours = $monthlyTimesheets->sum('hours');

        $myTasks = WorkTask::where(fn ($q) => $q->where('assignee_id', $user->id)->orWhere('creator_id', $user->id))
            ->latest()
            ->take(6)
            ->get();
        $pendingTasksCount = WorkTask::where('assignee_id', $user->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->count();

        $showTickets = $user->can('support_ticket.view') || $user->can('support_ticket.create');
        $myTickets = $showTickets
            ? SupportTicket::where(fn ($q) => $q->where('creator_id', $user->id)->orWhere('assignee_id', $user->id))
                ->latest()
                ->take(5)
                ->get()
            : collect();

        $myActivities = class_exists(\Spatie\Activitylog\Models\Activity::class)
            ? \Spatie\Activitylog\Models\Activity::where('causer_id', $user->id)->latest()->take(6)->get()
            : collect();

        // Báo cáo định kỳ: đã nộp kỳ hiện tại chưa (Học vụ ngày / Học thuật tuần / GV-TA tháng).
        $reportCard = null;
        if ($user->can('staff_report.submit')) {
            $cadence = StaffType::reportCadence($user);
            [$from, $to] = match ($cadence) {
                'weekly' => [now()->startOfWeek(), now()->endOfWeek()],
                'monthly' => [now()->startOfMonth(), now()->endOfMonth()],
                default => [now()->startOfDay(), now()->endOfDay()],
            };
            $submitted = StaffReport::where('user_id', $user->id)
                ->where('type', $cadence)
                ->whereBetween('report_date', [$from->toDateString(), $to->toDateString()])
                ->exists();
            [$label, $when] = self::REPORT_LABELS[$cadence] ?? self::REPORT_LABELS['daily'];
            $reportCard = [
                'label' => $label,
                'icon' => 'summarize',
                'tone' => $submitted ? 'success' : 'warning',
                'value' => $submitted ? 'Đã nộp' : 'Chưa nộp',
                'hint' => $when,
                'href' => route('reports.my'),
            ];
        }

        // Hoa hồng tuyển sinh tạm tính tháng này: Học vụ (người phụ trách khách) và ai đang có khách chốt / khoản hoa hồng.
        $commission = null;
        if ($portal === 'academic_staff'
            || CommissionItem::where('user_id', $user->id)->exists()
            || CrmCustomer::whereNotNull('converted_student_id')->where('commission_user_id', $user->id)->exists()) {
            $commission = app(SalesCommissionService::class)->statementFor($user->id, now());
        }
        // KPI Học vụ tạm tính tháng này theo từng đầu mục (KPI thực vào phiếu lương khi đánh giá tháng được chốt).
        $kpi = StaffType::usesAcademicStaffKpi($user)
            ? app(PayrollFormulaService::class)->academicKpiStatement($user, (int) now()->month, (int) now()->year)
            : null;
        if ($kpi && $user->can('kpi.view')) {
            $kpi['url'] = route('kpi.evaluate', $user->id);
        }

        $inbox = app(ApprovalInboxService::class);
        $approvalCount = $inbox->badge($user);

        $cards = [
            'payroll' => $showPayroll ? [
                'label' => 'Lương kỳ gần nhất', 'icon' => 'wallet', 'tone' => 'success',
                'value' => $latestPayroll ? Money::format($latestPayroll->net_salary) : 'Chưa chốt kỳ',
                'hint' => $latestPayroll?->period ? ($latestPayroll->period->title ?: 'Tháng '.$latestPayroll->period->month.'/'.$latestPayroll->period->year) : 'Hiện sau khi kỳ lương được duyệt',
                'href' => route('portal.my-salary'),
            ] : null,
            'hours' => $teaches ? [
                'label' => $portal === 'assistant' ? 'Giờ trợ giảng tháng này' : 'Giờ dạy tháng này', 'icon' => 'schedule', 'tone' => 'secondary',
                'value' => number_format($totalMonthlyHours, 1).' giờ',
                'hint' => 'Tháng '.now()->format('m/Y').' ('.$monthlyTimesheets->count().' ca)',
            ] : null,
            'classes' => $teaches ? [
                'label' => $portal === 'assistant' ? 'Lớp đang trợ giảng' : 'Lớp đang dạy', 'icon' => 'meeting_room', 'tone' => 'primary',
                'value' => $activeClassCount.' lớp', 'hint' => 'Đang hoạt động',
            ] : null,
            'approvals' => $approvalCount !== null ? [
                'label' => 'Việc cần duyệt', 'icon' => 'approval', 'tone' => $approvalCount > 0 ? 'warning' : 'success',
                'value' => $approvalCount.' mục', 'hint' => 'Đang chờ bạn duyệt',
                'href' => route('approvals.index'),
            ] : null,
            'report' => $reportCard,
            'commission' => $commission ? [
                'label' => 'Hoa hồng tạm tính', 'icon' => 'trending_up', 'tone' => 'success',
                'value' => Money::format($commission['expected']),
                'hint' => 'Khi thu đủ · '.$commission['closed'].' HS chốt tháng '.$commission['month_label'].' · mốc '.rtrim(rtrim(number_format($commission['percent'], 2, ',', ''), '0'), ',').'%',
            ] : null,
            'kpi' => $kpi ? [
                'label' => 'KPI tạm tính', 'icon' => 'insights', 'tone' => 'primary',
                'value' => Money::format($kpi['amount']),
                'hint' => 'Tháng '.$kpi['month_label'].' · quỹ '.Money::format($kpi['fund']).' · '.match ($kpi['status']) {
                    'confirmed' => 'đã chốt',
                    'draft' => 'đang chấm',
                    default => 'chưa chấm',
                },
            ] : null,
            'tasks' => [
                'label' => 'Việc cần làm', 'icon' => 'task_alt', 'tone' => 'warning',
                'value' => $pendingTasksCount.' việc', 'hint' => 'Được giao, đang trong tiến độ',
            ],
            'tickets' => $showTickets ? [
                'label' => 'Ticket cá nhân', 'icon' => 'confirmation_number', 'tone' => 'secondary',
                'value' => $myTickets->count().' yêu cầu', 'hint' => 'Gửi / được giao',
            ] : null,
        ];
        $order = match ($portal) {
            'teacher' => ['classes', 'hours', 'tasks', 'payroll', 'report'],
            'assistant' => ['tasks', 'classes', 'hours', 'payroll', 'report'],
            'academic_staff' => ['commission', 'kpi', 'tasks', 'approvals', 'report', 'payroll', 'tickets'],
            'academic_lead' => ['approvals', 'report', 'tasks', 'payroll', 'tickets'],
            default => ['approvals', 'tasks', 'payroll', 'report', 'tickets'],
        };
        $statCards = collect($order)->map(fn ($key) => $cards[$key])->filter()->take(4)->values()->all();

        // GV / TA xem hết việc ở "Nhiệm vụ hôm nay" (cổng GV); người giao việc dùng danh sách Công việc.
        $tasksRoute = $user->can('work_task.create') ? 'tasks.index' : 'portal.ta-tasks';
        $canViewTasks = $user->can('work_task.view');

        return Inertia::render('Profile/Edit', [
            ...$this->accountProps($request, $user, showOperations: true),
            'portal' => $portal,
            'teaches' => $teaches,
            'roleLabels' => $this->roleLabels($user),
            'quickLinks' => $this->quickLinks($user, $portal, $request),
            'statCards' => $statCards,
            'showPayroll' => $showPayroll,
            'showTickets' => $showTickets,
            'showOperations' => true,
            'commission' => $commission,
            'kpi' => $kpi,
            'payrollPeriodLabel' => $latestPayroll?->period?->title ?? 'Tháng '.now()->format('m/Y'),
            'latestPayroll' => $latestPayroll ? [
                'base_salary' => $latestPayroll->base_salary,
                'teaching_salary' => $latestPayroll->teaching_salary,
                'kpi_bonus' => Money::format($latestPayroll->kpi_bonus),
                'renew_bonus' => Money::format($latestPayroll->renew_bonus),
                'allowance' => Money::format($latestPayroll->allowance),
                'insurance_deduction' => Money::format($latestPayroll->insurance_deduction),
                'tax_deduction' => Money::format($latestPayroll->tax_deduction),
                'penalty_deduction' => Money::format($latestPayroll->penalty_deduction),
                'net_salary' => Money::format($latestPayroll->net_salary),
                'status_label' => $latestPayroll->status === 'paid' ? 'Đã thanh toán' : ($latestPayroll->status === 'approved' ? 'Đã duyệt chi' : 'Dự thảo'),
            ] : null,
            'recentPayrolls' => $recentPayrolls->map(fn (PayrollRecord $p) => [
                'id' => $p->id,
                'title' => $p->period?->title ?? 'Kỳ '.$p->created_at->format('m/Y'),
                'base_salary' => $p->base_salary,
                'income' => Money::format($p->teaching_salary + $p->kpi_bonus + $p->renew_bonus),
                'deduction' => Money::format($p->insurance_deduction + $p->tax_deduction + $p->penalty_deduction),
                'net_salary' => Money::format($p->net_salary),
                'paid' => $p->status === 'paid',
                'status_label' => StatusLabel::for($p->status),
            ])->values()->all(),
            'recentTimesheets' => $recentTimesheets->map(fn (TeacherTimesheet $ts) => [
                'id' => $ts->id,
                'class_code' => $ts->classModel?->code,
                'date' => Carbon::parse($ts->teaching_date ?? $ts->date)->format('d/m/Y'),
                'hours' => $ts->hours,
                'status_label' => $ts->status_label ?? StatusLabel::for($ts->status),
            ])->values()->all(),
            'myTasks' => $myTasks->map(fn (WorkTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'due_date' => $task->due_date?->format('d/m/Y'),
                'time_slot_category' => $task->time_slot_category,
                'url' => $canViewTasks ? ($tasksRoute === 'tasks.index' ? route('tasks.show', $task->id) : route($tasksRoute)) : null,
            ])->values()->all(),
            'tasksUrl' => $canViewTasks ? route($tasksRoute) : null,
            'assignedClasses' => $assignedClasses->map(fn (ClassModel $cls) => [
                'id' => $cls->id,
                'code' => $cls->code,
                'name' => $cls->name,
                'status_label' => StatusLabel::for($cls->status),
                'course_name' => $cls->course?->name,
                'schedule_text' => $cls->schedule_text,
            ])->values()->all(),
            'myTickets' => $myTickets->map(fn (SupportTicket $ticket) => [
                'id' => $ticket->id,
                'code' => $ticket->code,
                'title' => $ticket->title,
                'priority' => $ticket->priority,
                'priority_label' => $ticket->priority_label,
                'category_label' => $ticket->category_label,
                'created_at' => $ticket->created_at->format('d/m/Y H:i'),
                'assignee_name' => $ticket->assignee?->name,
                'status' => $ticket->status,
                'status_label' => $ticket->status_label,
            ])->values()->all(),
            'myActivities' => $myActivities->map(fn ($act) => [
                'id' => $act->id,
                'description' => $act->description,
                'ago' => $act->created_at->diffForHumans(),
            ])->values()->all(),
        ]);
    }

    /**
     * Phần chung của trang cá nhân: thông tin tài khoản (form hồ sơ + đổi mật khẩu), tab mở đầu và trạng thái sau khi lưu.
     * Tab mở đầu: đổi mật khẩu bắt buộc / học viên vào thẳng "Cài đặt tài khoản"; nhân sự vào tab công việc.
     */
    private function accountProps(Request $request, User $user, bool $showOperations): array
    {
        return [
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'initial' => Str::substr($user->name ?? 'A', 0, 1),
                'branch_name' => $user->branch?->name,
                'staff_code' => '#NV-'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT),
                'must_change_password' => (bool) $user->must_change_password,
                'email_unverified' => $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail(),
            ],
            'canSendVerification' => Route::has('verification.send'),
            'initialTab' => ($user->must_change_password || ! $showOperations) ? 'settings' : 'operations',
            // Trạng thái dạng mã (profile-updated, password-updated, verification-link-sent): trang tự hiện dòng xác nhận.
            'status' => $request->session()->get('status'),
        ];
    }

    /**
     * Cổng chính của người dùng theo chức danh. Người kiêm nhiệm: chức danh văn phòng (Học thuật, Học vụ) đứng trước
     * đứng lớp; phần đứng lớp (giờ dạy, lớp) vẫn hiện nếu có quyền cổng GV / TA.
     */
    private function portalFor(User $user): string
    {
        if ($user->isPortalStudentOnly()) {
            return 'student';
        }
        $roles = $user->getRoleNames();

        return match (true) {
            $roles->contains('academic_lead') || $roles->contains('academic') => 'academic_lead',
            $roles->contains('academic_staff') => 'academic_staff',
            $roles->intersect(['admin', 'manager', 'accountant', 'sales_consultant'])->isNotEmpty() => 'staff',
            $roles->intersect(StaffType::TEACHER_ROLES)->isNotEmpty() || $user->can('portal.teacher') => 'teacher',
            $roles->contains('assistant') || $user->can('portal.assistant') => 'assistant',
            default => 'staff',
        };
    }

    /** @return list<string> */
    private function roleLabels(User $user): array
    {
        return $user->getRoleNames()->map(fn (string $role) => AclHelper::shortRoleLabel($role))->values()->all();
    }

    /** @return list<array{label: string, icon: string, url: string}> */
    private function quickLinks(User $user, string $portal, Request $request): array
    {
        $menu = app(SidebarMenu::class);

        return collect(self::QUICK_LINKS[$portal] ?? self::QUICK_LINKS['staff'])
            ->filter(fn (array $link) => $menu->canSee($user, $link, $request))
            ->map(fn (array $link) => ['label' => $link['label'], 'icon' => $link['icon'], 'url' => route($link['route'])])
            ->values()
            ->all();
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->safe()->only(['name', 'email']));

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }
}
