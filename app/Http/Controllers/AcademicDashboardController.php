<?php

namespace App\Http\Controllers;

use App\Models\AcademicRecord;
use App\Models\BigTest;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\StaffReport;
use App\Models\StudentAttendance;
use App\Models\SupportTicket;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\User;
use App\Support\DataScope;
use App\Support\Ui;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AcademicDashboardController extends Controller
{
    /**
     * Phạm vi dữ liệu của hai dashboard theo "class.scope_*" (DataScope): Toàn hệ thống (Admin, Học thuật, Học vụ) thấy mọi
     * cơ sở; Chi nhánh (Quản lý cơ sở) chỉ thấy lớp trong phạm vi (ClassModel::visibleTo) và báo cáo / nhật ký do nhân sự
     * chi nhánh mình viết; Của tôi → lớp mình + báo cáo của chính mình (rà soát 19/10/2026).
     *
     * @return array{branchIds: list<int>|null, staffIds: list<int>|null, classIds: \Closure(): Builder}
     */
    private function scope(User $user): array
    {
        $branchIds = DataScope::branchIds($user, 'class');

        return [
            'branchIds' => $branchIds,
            'staffIds' => match (DataScope::level($user, 'class')) {
                DataScope::ALL => null,
                DataScope::BRANCH => StaffReportController::staffIdsInBranches($branchIds, (int) $user->id),
                default => [(int) $user->id],
            },
            'classIds' => fn () => ClassModel::visibleTo($user)->select('id'),
        ];
    }

    /**
     * Dashboard Báo cáo Admin:
     * Tổng hợp báo cáo ngày của Học vụ (+ báo cáo trực lớp), tuần của Học thuật (+ tuần KPI Học vụ), tháng / quý của Giáo viên và Học thuật.
     */
    public function reports(Request $request): Response
    {
        $tab = $request->get('tab', 'daily'); // 'daily', 'weekly', 'monthly'
        ['branchIds' => $branchIds, 'staffIds' => $staffIds, 'classIds' => $classIds] = $this->scope($request->user());
        $scoped = $branchIds !== null;
        $byClass = fn ($q) => $q->when($scoped, fn ($q) => $q->whereIn('class_id', $classIds()));
        $byAuthor = fn ($q) => $q->when($staffIds !== null, fn ($q) => $q->whereIn('user_id', $staffIds));

        // Báo cáo trực lớp theo buổi (GV / TA), hiện cùng tab báo cáo ngày.
        $classReports = $byClass(ClassReport::with(['classModel', 'reporter'])->withCount('studentSupports'))->latest()->take(10)->get();

        // Báo cáo nhân sự đã nộp (staff_reports, bỏ bản nháp): 1. báo cáo ngày của Học vụ, 2. báo cáo tuần của Học thuật
        // (+ báo cáo tuần KPI của Học vụ, bảng riêng), 3. báo cáo tháng / quý (giáo viên, Học thuật).
        $submitted = fn () => $byAuthor(StaffReport::with('user:id,name'))->where('status', '!=', 'draft')->latest('report_date')->latest('id');
        $staffDailyReports = $tab === 'daily' ? $submitted()->where('type', 'daily')->take(15)->get() : collect();
        $weeklyReports = $tab === 'weekly' ? $submitted()->where('type', 'weekly')
            ->where(fn ($q) => $q->where('kind', 'academic_weekly')->orWhereNull('kind'))->take(10)->get() : collect();
        $weeklyKpiReports = $tab === 'weekly' ? $submitted()->where('kind', 'weekly_kpi')->take(10)->get() : collect();
        $monthlyReports = $tab === 'monthly' ? $submitted()->whereIn('type', ['monthly', 'quarterly'])->take(15)->get() : collect();

        // Thống kê tổng hợp (số liệu thật; không có dữ liệu thì view hiện "Chưa có dữ liệu")
        $totalClasses = ClassModel::visibleTo($request->user())->count();
        $activeClasses = ClassModel::visibleTo($request->user())->where('status', 'active')->count();
        $totalDailyReportsToday = $byClass(ClassReport::whereDate('created_at', today()))->count()
            + $byAuthor(StaffReport::where('type', 'daily')->whereDate('report_date', today()))->count();

        $todayAttendance = $byClass(StudentAttendance::whereDate('session_date', today()))->get(['status']);
        $attendanceRateToday = $todayAttendance->isEmpty()
            ? null
            : round($todayAttendance->whereIn('status', ['present', 'late'])->count() * 100 / $todayAttendance->count(), 1);

        // Sĩ số / có mặt theo lớp + ngày của từng báo cáo trực lớp
        $reportAttendance = [];
        $reportsWithSession = $classReports->filter(fn ($cr) => $cr->class_id && $cr->session_date);
        // Điểm danh của mọi (lớp, ngày) trong các báo cáo: 1 truy vấn rồi nhóm lại, thay vì 1 truy vấn / báo cáo.
        $attendanceByClassDay = $reportsWithSession->isEmpty() ? collect() : StudentAttendance::query()
            ->whereIn('class_id', $reportsWithSession->pluck('class_id')->unique()->values())
            ->whereDate('session_date', '>=', Carbon::parse($reportsWithSession->min('session_date'))->toDateString())
            ->whereDate('session_date', '<=', Carbon::parse($reportsWithSession->max('session_date'))->toDateString())
            ->get(['class_id', 'session_date', 'status'])
            ->groupBy(fn ($row) => $row->class_id.'|'.Carbon::parse($row->session_date)->toDateString());
        foreach ($reportsWithSession as $cr) {
            $rows = $attendanceByClassDay->get($cr->class_id.'|'.Carbon::parse($cr->session_date)->toDateString(), collect());
            if ($rows->isNotEmpty()) {
                $reportAttendance[$cr->id] = [
                    'total' => $rows->count(),
                    'present' => $rows->whereIn('status', ['present', 'late'])->count(),
                    'excused' => $rows->where('status', 'excused')->count(),
                    'absent' => $rows->where('status', 'absent')->count(),
                ];
            }
        }

        // Tuần này: số liệu học thuật thật
        $weekStart = now()->startOfWeek();
        $weeklyStats = [
            'adjustments_pending' => $byClass(SyllabusAdjustmentRequest::where('status', 'pending'))->count(),
            'adjustments_week' => $byClass(SyllabusAdjustmentRequest::where('created_at', '>=', $weekStart))->count(),
            'big_tests_upcoming' => $byClass(BigTest::whereBetween('scheduled_at', [now(), now()->addDays(7)]))->count(),
            'big_tests_distributed' => $byClass(BigTest::where('is_distributed', true)->where('distributed_at', '>=', $weekStart))->count(),
        ];

        $recordRow = fn (StaffReport $report) => [
            'id' => $report->id,
            'title' => $report->title ?: 'Báo cáo',
            'record_code' => $report->period_key,
            'user' => $report->user?->name,
            'status_label' => $report->kind ? (StaffReportController::STRUCTURED[$report->kind]['label'] ?? $report->type_label) : $report->type_label,
            'content' => $report->content,
            'created_at' => $report->updated_at?->format('H:i d/m/Y'),
        ];
        $dailyRow = function (StaffReport $report) {
            $f = $report->data['fields'] ?? [];

            return [
                'id' => $report->id,
                'date' => $report->report_date->toDateString(),
                'user' => $report->user?->name,
                'title' => $report->title,
                'content' => $report->content,
                'structured' => $report->kind === 'daily_ops',
                'classes' => isset($f['classes_planned']) || isset($f['classes_actual']) ? ($f['classes_actual'] ?? '—').' / '.($f['classes_planned'] ?? '—') : null,
                'staff' => isset($f['teachers']) || isset($f['assistants']) ? ($f['teachers'] ?? 0).' GV · '.($f['assistants'] ?? 0).' TG' : null,
                'enrollment' => collect(['Data' => $f['new_data'] ?? null, 'Test' => $f['test_students'] ?? null, 'Thu phí' => $f['fees'] ?? null])
                    ->filter(fn ($v) => $v !== null)->map(fn ($v, $k) => "{$k} {$v}")->implode(' · ') ?: null,
                'task_id' => $report->data['task_id'] ?? null,
                'updated_at' => $report->updated_at?->format('H:i d/m/Y'),
            ];
        };

        return Inertia::render('Academic/Reports', [
            'tab' => $tab,
            'today' => now()->format('d/m/Y'),
            'classReports' => $tab === 'daily' ? $classReports->map(fn (ClassReport $cr) => [
                'id' => $cr->id,
                'class_name' => $cr->classModel?->name,
                'session_name' => $cr->session_name,
                'reporter' => $cr->reporter?->name,
                'attendance' => $reportAttendance[$cr->id] ?? null,
                'topics_learned' => $cr->topics_learned,
                'teaching_log' => $cr->teaching_log,
                'status_label' => $cr->status_label,
                'student_supports_count' => (int) $cr->student_supports_count,
                'created_at' => $cr->created_at->format('H:i d/m'),
            ])->values() : [],
            'staffDailyReports' => $staffDailyReports->map($dailyRow)->values(),
            'weeklyReports' => $weeklyReports->map($recordRow)->values(),
            'weeklyKpiReports' => $weeklyKpiReports->map($recordRow)->values(),
            'monthlyReports' => $monthlyReports->map($recordRow)->values(),
            'totalClasses' => $totalClasses,
            'activeClasses' => $activeClasses,
            'totalDailyReportsToday' => $totalDailyReportsToday,
            'attendanceRateToday' => $attendanceRateToday,
            'weeklyStats' => $weeklyStats,
        ]);
    }

    /**
     * Dashboard Nhật ký sự vụ Admin:
     * Tổng hợp các sự vụ nổi cộm từ các cơ sở, phân loại mức độ và tiến độ xử lý.
     */
    public function incidents(Request $request): Response
    {
        $severity = in_array($request->get('severity'), ['urgent', 'high', 'medium', 'low'], true) ? $request->get('severity') : 'all';
        $status = in_array($request->get('status'), ['open', 'resolved'], true) ? $request->get('status') : 'all';
        $user = $request->user();
        ['branchIds' => $branchIds, 'staffIds' => $staffIds, 'classIds' => $classIds] = $this->scope($user);
        // Lọc cơ sở ngoài phạm vi → bỏ qua (không tin tham số branch_id).
        $branchId = $request->integer('branch_id') ?: null;
        if ($branchId && ! DataScope::coversBranch($user, 'class', $branchId)) {
            $branchId = null;
        }

        $branches = Branch::where('is_active', true)->when($branchIds !== null, fn ($q) => $q->whereIn('id', $branchIds))->get();

        // Dữ liệu nhật ký sự vụ từ academic_records (05_nhat_ky_hoc_vu) — bỏ bản ghi seed demo; theo người ghi trong phạm vi.
        $incidentsQuery = AcademicRecord::forScreen('05_nhat_ky_hoc_vu')
            ->where('is_seed', false)
            ->when($staffIds !== null, fn ($q) => $q->whereIn('user_id', $staffIds))
            ->with('user.branch')
            ->latest();
        if ($branchId) {
            $incidentsQuery->whereHas('user', fn ($q) => $q->where('branch_id', $branchId));
        }
        if ($status === 'resolved') {
            $incidentsQuery->whereIn('status', ['resolved', 'completed', 'closed']);
        } elseif ($status === 'open') {
            $incidentsQuery->whereNotIn('status', ['resolved', 'completed', 'closed']);
        }
        $incidents = $severity === 'all' ? $incidentsQuery->take(20)->get() : collect();

        // Nhật ký sự vụ thật (staff_reports, type = journal) — có thể gắn lớp; lọc cơ sở theo lớp, không có lớp thì theo người ghi.
        $classId = $request->integer('class_id') ?: null;
        $journalSeverity = ['urgent' => 'urgent', 'high' => 'important', 'medium' => 'normal'][$severity] ?? null;
        $journals = ($severity === 'all' || $journalSeverity)
            ? StaffReport::with(['user.branch', 'classModel.branch', 'followups'])
                ->where('type', 'journal')
                // Phạm vi: nhật ký gắn lớp → lớp trong phạm vi; không gắn lớp → người ghi thuộc chi nhánh mình.
                ->when($branchIds !== null, fn ($q) => $q->where(fn ($q) => $q
                    ->whereIn('class_id', $classIds())
                    ->orWhere(fn ($q) => $q->whereNull('class_id')->whereIn('user_id', $staffIds))))
                ->when($journalSeverity, fn ($q) => $q->where('severity', $journalSeverity))
                ->when($classId, fn ($q) => $q->where('class_id', $classId))
                ->when($branchId, fn ($q) => $q->where(fn ($q) => $q
                    ->whereHas('classModel', fn ($c) => $c->where('branch_id', $branchId))
                    ->orWhere(fn ($q) => $q->whereNull('class_id')->whereHas('user', fn ($u) => $u->where('branch_id', $branchId)))))
                ->when($status === 'resolved', fn ($q) => $q->where('status', 'resolved'))
                ->when($status === 'open', fn ($q) => $q->where('status', '!=', 'resolved'))
                ->latest('report_date')->latest('id')->take(30)->get()
            : collect();
        $classes = ClassModel::visibleTo($user)->where('status', '!=', 'cancelled')->orderBy('code')->get(['id', 'code', 'name']);

        // Support tickets nổi cộm (model có creator/assignee, không có relation user): cùng phạm vi màn Ticket — chỉ ticket
        // người xem được mở (SupportTicket::visibleTo).
        $ticketsQuery = SupportTicket::visibleTo($user)->with(['creator.branch', 'assignee'])
            ->whereIn('priority', $severity === 'all' ? ['urgent', 'high'] : [$severity])
            ->latest();
        if ($branchId) {
            $ticketsQuery->whereHas('creator', fn ($q) => $q->where('branch_id', $branchId));
        }
        if ($status === 'resolved') {
            $ticketsQuery->whereIn('status', ['resolved', 'closed']);
        } elseif ($status === 'open') {
            $ticketsQuery->whereIn('status', ['open', 'in_progress']);
        }
        $urgentTickets = $ticketsQuery->take(20)->get();

        // Ticket hỗ trợ không gắn lớp: khi lọc theo lớp thì chỉ còn sự vụ của lớp đó.
        if ($classId) {
            $urgentTickets = collect();
            $incidents = collect();
        }

        // Thống kê sự vụ
        $totalIncidents = $incidents->count() + $urgentTickets->count() + $journals->count();
        $resolvedCount = $incidents->whereIn('status', ['resolved', 'completed', 'closed'])->count()
            + $urgentTickets->whereIn('status', ['resolved', 'closed'])->count()
            + $journals->where('status', 'resolved')->count();
        $openCount = max(0, $totalIncidents - $resolvedCount);
        $urgentCount = $urgentTickets->where('priority', 'urgent')->whereIn('status', ['open', 'in_progress'])->count()
            + $journals->where('severity', 'urgent')->where('status', '!=', 'resolved')->count();

        $canManageTickets = SupportTicket::userCanManage($user);
        $journalSeverityMap = ['urgent' => 'urgent', 'important' => 'high', 'normal' => 'medium'];

        return Inertia::render('Academic/Incidents', [
            'severity' => $severity,
            'status' => $status,
            'branchId' => (string) $branchId,
            'classId' => (string) $classId,
            'branches' => Ui::options($branches, 'name'),
            'classes' => Ui::options($classes, 'code'),
            'tickets' => $urgentTickets->map(fn (SupportTicket $ticket) => [
                'id' => $ticket->id,
                'code' => $ticket->code,
                'can_open' => $canManageTickets || (int) $ticket->creator_id === (int) $user->id || (int) $ticket->assignee_id === (int) $user->id,
                'branch' => $ticket->creator?->branch?->name,
                'category_label' => $ticket->category_label,
                'title' => $ticket->title,
                'excerpt' => Str::limit(strip_tags((string) $ticket->description), 140),
                'priority' => $ticket->priority,
                'assignee' => $ticket->assignee?->name,
                'creator' => $ticket->creator?->name,
                'status' => $ticket->status,
                'status_label' => $ticket->status_label,
            ])->values(),
            'journals' => $journals->map(fn (StaffReport $journal) => [
                'id' => $journal->id,
                'branch' => $journal->classModel?->branch?->name ?? $journal->user?->branch?->name,
                'class_id' => $journal->classModel ? $journal->class_id : null,
                'class_code' => $journal->classModel?->code,
                'title' => $journal->title,
                'content' => $journal->content,
                'severity' => $journal->severity,
                'priority' => $journalSeverityMap[$journal->severity] ?? null,
                'severity_label' => $journal->severity_label,
                'user' => $journal->user?->name,
                'followup' => $journal->followups->first()?->content,
                'status' => $journal->status,
            ])->values(),
            'incidents' => $incidents->map(fn (AcademicRecord $incident) => [
                'id' => $incident->id,
                'code' => $incident->record_code ?: 'Nhật ký #'.$incident->id,
                'branch' => $incident->user?->branch?->name,
                'title' => $incident->title,
                'content' => ($incident->data['noi_dung'] ?? null) ?: null,
                'user' => $incident->user?->name,
                'status_label' => $incident->status_label,
            ])->values(),
            'totalIncidents' => $totalIncidents,
            'resolvedCount' => $resolvedCount,
            'openCount' => $openCount,
            'urgentCount' => $urgentCount,
            'canSubmitJournal' => $user->can('staff_report.submit'),
        ]);
    }
}
