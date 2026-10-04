<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use App\Models\KpiCriterion;
use App\Models\MaterialOrder;
use App\Models\StaffReport;
use App\Models\StaffReportFollowup;
use App\Models\TeacherMeetingReport;
use App\Models\User;
use App\Support\Dashboard\TeachingQuality;
use App\Support\MonthlyReportDue;
use App\Support\ReportPeriod;
use App\Support\StaffType;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Báo cáo & Nhật ký (quyền staff_report.*):
 *  - staff_report.submit: ghi nhật ký sự vụ, nộp báo cáo định kỳ của mình. Kỳ báo cáo theo chức danh (StaffType):
 *    Học vụ NGÀY, Học thuật TUẦN, giáo viên / trợ giảng THÁNG.
 *  - Báo cáo có cấu trúc (StaffType::structuredReports): Học vụ thêm báo cáo TUẦN theo mục KPI; Học thuật thêm báo cáo
 *    THÁNG / QUÝ (tường thuật + tổng hợp báo cáo tuần, họp giáo viên); giáo viên thêm báo cáo THÁNG theo lớp. Mỗi người 1 báo cáo / kỳ (staff_reports.period_key),
 *    nộp lại trong kỳ là cập nhật. `content` lưu bản chữ để màn Tổng hợp đọc được như báo cáo thường.
 *  - staff_report.view_all: xem tổng tất cả nhật ký & báo cáo của mọi người (mặc định Admin / Quản lý cơ sở).
 */
class StaffReportController extends Controller
{
    /** Báo cáo có cấu trúc: loại staff_reports.type + route màn nhập. */
    public const STRUCTURED = [
        'weekly_kpi' => ['label' => 'Báo cáo tuần (KPI)', 'type' => 'weekly', 'route' => 'reports.periodic.weekly-kpi', 'for' => 'Học vụ'],
        'academic_monthly' => ['label' => 'Báo cáo tháng', 'type' => 'monthly', 'route' => 'reports.periodic.academic-monthly', 'for' => 'Học thuật'],
        'academic_quarterly' => ['label' => 'Báo cáo quý', 'type' => 'quarterly', 'route' => 'reports.periodic.academic-quarterly', 'for' => 'Học thuật'],
        'teacher_monthly' => ['label' => 'Báo cáo tháng theo lớp', 'type' => 'monthly', 'route' => 'reports.periodic.teacher-monthly', 'for' => 'giáo viên'],
    ];

    /** Báo cáo tháng của giáo viên — phần chung (chủ dự án 04/10/2026). */
    public const TEACHER_GENERAL_FIELDS = [
        'progress' => 'Tiến độ giảng dạy',
        'difficulties' => 'Khó khăn',
        'proposals' => 'Đề xuất',
    ];

    /** Báo cáo tháng của giáo viên — tình hình từng lớp (ngoài ô "Cần hỗ trợ"). */
    public const TEACHER_CLASS_FIELDS = [
        'situation' => 'Tình hình lớp',
        'attention' => 'Học sinh cần chú ý',
        'solution' => 'Giải pháp',
    ];

    /** Chỉ số quy mô trong báo cáo tuần Học vụ (theo dõi, không tính KPI). */
    public const WEEKLY_METRICS = [
        'classes_running' => 'Số lớp đang chạy',
        'students' => 'Số học sinh',
        'teachers' => 'Số GV phụ trách',
        'new_leads' => 'Data mới nhận',
        'new_closed' => 'HV mới chốt',
        'transfers' => 'HV chuyển lớp',
        'makeups' => 'HV học bù',
        'care_due_classes' => 'Lớp đến hạn chăm sóc',
        'upcoming_test_classes' => 'Số lớp sắp lịch test',
    ];

    /** Phần tường thuật báo cáo tháng Học thuật. */
    public const MONTHLY_FIELDS = [
        'test_syllabus_review' => 'Rà soát test & syllabus',
        'materials_transfers' => 'Chuẩn bị giáo trình & chuyển lớp',
        'overall' => 'Đánh giá chung tháng',
        'teacher_notes' => 'Nhận xét riêng theo từng giáo viên (tùy chọn)',
    ];

    /** Báo cáo quý Học thuật: nhóm "Nội dung báo cáo" và "Đánh giá chung". */
    public const QUARTERLY_SECTIONS = [
        'Nội dung báo cáo' => [
            'academic_work' => 'Công việc học thuật',
            'teacher_staffing' => 'Nhân sự giáo viên',
            'next_plan' => 'Kế hoạch công việc kỳ sau',
        ],
        'Đánh giá chung' => [
            'program_progress' => 'Tiến độ chương trình',
            'implementation_quality' => 'Chất lượng triển khai',
            'improvement_priorities' => 'Ưu tiên cải thiện',
        ],
    ];

    private function guard(): void
    {
        abort_unless(Auth::user()?->can('staff_report.submit'), 403);
    }

    private function isPrivileged(): bool
    {
        return (bool) Auth::user()?->can('staff_report.view_all');
    }

    /** Loại báo cáo định kỳ chính theo chức danh. */
    private function primaryType(User $u): string
    {
        return StaffType::reportCadence($u);
    }

    // ───────────────────────── NHẬT KÝ ─────────────────────────
    public function journal(Request $request): Response
    {
        $this->guard();
        $isPriv = $this->isPrivileged();

        $query = StaffReport::with(['user', 'followups.user', 'classModel:id,code,name'])
            ->where('type', 'journal');
        if (! $isPriv) {
            $query->where('user_id', Auth::id());
        }
        if ($sev = $request->input('severity')) {
            $query->where('severity', $sev);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($classId = $request->integer('class_id')) {
            $query->where('class_id', $classId);
        }

        $journals = $query->latest('report_date')->latest('id')->paginate(15)->withQueryString()
            ->through(fn (StaffReport $j) => [
                'id' => $j->id,
                'title' => $j->title,
                'content' => $j->content,
                'severity' => $j->severity,
                'severity_label' => $j->severity_label,
                'status' => $j->status,
                'report_date' => $j->report_date->toDateString(),
                'class' => $j->classModel ? ['id' => $j->class_id, 'code' => $j->classModel->code] : null,
                'user' => $isPriv ? $j->user?->name : null,
                'followups' => $j->followups->map(fn (StaffReportFollowup $f) => [
                    'id' => $f->id,
                    'user' => $f->user?->name,
                    'content' => $f->content,
                    'created_at' => $f->created_at->toIso8601String(),
                ])->all(),
            ]);
        // Lớp gắn được sự vụ: chỉ lớp người ghi thấy (đang mở).
        $classes = ClassModel::visibleTo(Auth::user())->where('status', '!=', 'cancelled')->orderBy('code')->get(['id', 'code', 'name']);

        return Inertia::render('Reports/Journal', [
            'journals' => $journals,
            'isPriv' => $isPriv,
            'classes' => Ui::options($classes, fn (ClassModel $c) => $c->code.' · '.$c->name),
            'today' => now()->toDateString(),
        ]);
    }

    public function journalStore(Request $request)
    {
        $this->guard();
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'nullable|string',
            'severity' => 'required|in:normal,important,urgent',
            'report_date' => 'nullable|date',
            'class_id' => 'nullable|integer',
        ]);
        $classId = null;
        if (! empty($validated['class_id'])) {
            $class = ClassModel::visibleTo(Auth::user())->find($validated['class_id']);
            abort_unless($class, 403, 'Lớp học này nằm ngoài phạm vi bạn được xem.');
            $classId = $class->id;
        }

        StaffReport::create([
            'user_id' => Auth::id(),
            'class_id' => $classId,
            'type' => 'journal',
            'title' => $validated['title'],
            'content' => $validated['content'] ?? null,
            'severity' => $validated['severity'],
            'report_date' => $validated['report_date'] ?? now()->toDateString(),
            'status' => 'open',
        ]);

        return back()->with('success', 'Đã ghi nhận sự vụ vào nhật ký!');
    }

    public function journalFollowup(Request $request, int $id)
    {
        $this->guard();
        $report = StaffReport::findOrFail($id);
        $validated = $request->validate([
            'content' => 'required|string',
        ], ['content.required' => 'Vui lòng nhập nội dung tác vụ / follow-up.']);

        StaffReportFollowup::create([
            'staff_report_id' => $report->id,
            'user_id' => Auth::id(),
            'content' => $validated['content'],
        ]);

        if ($report->status === 'open') {
            $report->update(['status' => 'following']);
        }

        return back()->with('success', 'Đã thêm follow-up cho sự vụ!');
    }

    public function journalStatus(Request $request, int $id)
    {
        $this->guard();
        $report = StaffReport::findOrFail($id);
        $validated = $request->validate([
            'status' => 'required|in:open,following,resolved',
        ]);
        $report->update(['status' => $validated['status']]);

        return back()->with('success', 'Đã cập nhật trạng thái sự vụ!');
    }

    // ───────────────────────── BÁO CÁO ĐỊNH KỲ ─────────────────────────
    public function myReports(Request $request): Response
    {
        $this->guard();
        $type = $this->primaryType(Auth::user());

        $reports = StaffReport::where('user_id', Auth::id())
            ->where('type', $type)
            ->whereNull('period_key')
            ->latest('report_date')
            ->paginate(10)
            ->through(fn (StaffReport $r) => [
                'id' => $r->id,
                'title' => $r->title,
                'content' => $r->content,
                'report_date' => $r->report_date->toDateString(),
            ]);

        return Inertia::render('Reports/My', [
            'reports' => $reports,
            'label' => StaffReport::TYPE_LABELS[$type] ?? 'Báo cáo',
            'today' => now()->toDateString(),
            // Báo cáo tháng (GV / trợ giảng): hạn Chủ nhật cuối tháng — chỉ nhắc, không phạt.
            'monthlyDue' => $type === 'monthly' ? [
                'due_label' => 'Chủ nhật '.MonthlyReportDue::dueDate(now())->format('d/m'),
                'month_label' => now()->format('m/Y'),
                'submitted' => StaffReport::where('user_id', Auth::id())->where('type', 'monthly')
                    ->whereBetween('report_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->exists(),
            ] : null,
            'tabs' => $this->reportTabs(Auth::user(), null),
        ]);
    }

    public function reportStore(Request $request)
    {
        $this->guard();
        $type = $this->primaryType(Auth::user());

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'report_date' => 'nullable|date',
            'class_id' => 'nullable|integer',
        ]);
        $classId = null;
        if (! empty($validated['class_id'])) {
            $class = ClassModel::visibleTo(Auth::user())->find($validated['class_id']);
            abort_unless($class, 403, 'Lớp học này nằm ngoài phạm vi bạn được xem.');
            $classId = $class->id;
        }

        StaffReport::create([
            'user_id' => Auth::id(),
            'class_id' => $classId,
            'type' => $type,
            'title' => $validated['title'],
            'content' => $validated['content'],
            'report_date' => $validated['report_date'] ?? now()->toDateString(),
            'status' => 'submitted',
        ]);

        return back()->with('success', 'Đã nộp ' . (StaffReport::TYPE_LABELS[$type] ?? 'báo cáo') . ' thành công!');
    }

    // ───────────────────────── BÁO CÁO CÓ CẤU TRÚC ─────────────────────────
    /** Báo cáo tuần Học vụ: số lần phát sinh theo từng mục KPI đang áp dụng + chỉ số quy mô. */
    public function weeklyKpi(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->redirectUnlessStructured('weekly_kpi')) {
            return $redirect;
        }
        $week = ReportPeriod::pick($request->input('week'), ReportPeriod::WEEK_PATTERN, ReportPeriod::currentWeek());
        $report = $this->structuredReport('weekly_kpi', $week);
        $counts = collect($report?->data['counts'] ?? [])->pluck('count', 'id');

        return Inertia::render('Reports/WeeklyKpi', [
            'tabs' => $this->reportTabs(Auth::user(), 'weekly_kpi'),
            'week' => $week,
            'weeks' => ReportPeriod::weekOptions(),
            'groups' => KpiCriterion::active()->ordered()->get()
                ->groupBy('group_name')
                ->map(fn ($items, $group) => [
                    'name' => $group,
                    'items' => $items->map(fn (KpiCriterion $c) => [
                        'id' => $c->id,
                        'code' => $c->code,
                        'name' => $c->name,
                        'count' => $counts[$c->id] ?? null,
                    ])->values(),
                ])->values(),
            'metrics' => self::WEEKLY_METRICS,
            'metricValues' => $report?->data['metrics'] ?? [],
            'submittedAt' => $report?->updated_at?->toIso8601String(),
            'history' => $this->structuredHistory('weekly_kpi', fn (string $key) => ReportPeriod::weekLabel($key), 'week'),
        ]);
    }

    public function weeklyKpiStore(Request $request): RedirectResponse
    {
        $this->guardStructured('weekly_kpi');
        $data = $request->validate([
            'week' => ['required', 'regex:'.ReportPeriod::WEEK_PATTERN],
            'counts' => ['nullable', 'array'],
            'counts.*' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'metrics' => ['nullable', 'array'],
            'metrics.*' => ['nullable', 'integer', 'min:0', 'max:99999'],
        ], ['counts.*.min' => 'Số lần phát sinh không âm.', 'metrics.*.min' => 'Chỉ số không âm.']);

        // Lưu kèm tên / nhóm mục KPI tại thời điểm nộp để báo cáo cũ vẫn đọc được khi Admin đổi cấu hình KPI.
        $counts = KpiCriterion::active()->ordered()->get()->map(fn (KpiCriterion $c) => [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'group' => $c->group_name,
            'count' => isset($data['counts'][$c->id]) ? (int) $data['counts'][$c->id] : 0,
        ])->values()->all();
        $metrics = collect(self::WEEKLY_METRICS)->keys()
            ->mapWithKeys(fn ($k) => [$k => isset($data['metrics'][$k]) ? (int) $data['metrics'][$k] : null])->all();

        $lines = collect($counts)->filter(fn ($c) => $c['count'] > 0)->map(fn ($c) => "{$c['group']} — {$c['name']}: {$c['count']} lần");
        $lines = $lines->merge(collect($metrics)->filter(fn ($v) => $v !== null)->map(fn ($v, $k) => self::WEEKLY_METRICS[$k].": {$v}"));
        [$start] = ReportPeriod::weekRange($data['week']);

        $this->saveStructured('weekly_kpi', $data['week'], 'Báo cáo tuần '.ReportPeriod::weekLabel($data['week']), $start->toDateString(),
            ['counts' => $counts, 'metrics' => $metrics], $lines->implode("\n") ?: 'Không có phát sinh trong tuần.');

        return back()->with('success', 'Đã lưu báo cáo tuần '.$data['week'].'.');
    }

    /** Báo cáo tháng Học thuật: tường thuật + báo cáo tuần và họp giáo viên trong tháng. */
    public function academicMonthly(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->redirectUnlessStructured('academic_monthly')) {
            return $redirect;
        }
        $month = ReportPeriod::pick($request->input('month'), ReportPeriod::MONTH_PATTERN, ReportPeriod::currentMonth());
        [$from, $to] = ReportPeriod::monthRange($month);
        $report = $this->structuredReport('academic_monthly', $month);

        return Inertia::render('Reports/AcademicMonthly', [
            'tabs' => $this->reportTabs(Auth::user(), 'academic_monthly'),
            'month' => $month,
            'months' => ReportPeriod::monthOptions(),
            'fields' => self::MONTHLY_FIELDS,
            'values' => $report?->data['narrative'] ?? [],
            'submittedAt' => $report?->updated_at?->toIso8601String(),
            'weeklyReports' => StaffReport::with('user:id,name')
                ->where('user_id', Auth::id())->where('type', 'weekly')->whereNull('period_key')
                ->whereDate('report_date', '>=', $from->toDateString())->whereDate('report_date', '<=', $to->toDateString())
                ->orderBy('report_date')->get()
                ->map(fn (StaffReport $r) => [
                    'id' => $r->id,
                    'title' => $r->title,
                    'week_start' => $r->report_date->copy()->startOfWeek()->toDateString(),
                    'week_end' => $r->report_date->copy()->endOfWeek()->toDateString(),
                    'user' => $r->user?->name,
                    'content' => $r->content,
                ])->values(),
            'meetings' => TeacherMeetingReport::with('teacher:id,name')
                ->where('author_id', Auth::id())
                ->whereDate('week_start', '>=', $from->copy()->startOfWeek()->toDateString())
                ->whereDate('week_start', '<=', $to->toDateString())
                ->orderBy('week_start')->get()
                ->map(fn (TeacherMeetingReport $m) => [
                    'id' => $m->id,
                    'week_start' => $m->week_start->toDateString(),
                    'teacher' => $m->teacher?->name,
                    'status_label' => TeacherMeetingReport::STATUSES[$m->status] ?? $m->status,
                    'status_color' => TeacherMeetingReport::STATUS_COLORS[$m->status] ?? 'neutral',
                ])->values(),
            'history' => $this->structuredHistory('academic_monthly', fn (string $key) => ReportPeriod::monthLabel($key), 'month'),
        ]);
    }

    public function academicMonthlyStore(Request $request): RedirectResponse
    {
        $this->guardStructured('academic_monthly');
        $data = $this->validatedNarrative($request, 'month', ReportPeriod::MONTH_PATTERN, self::MONTHLY_FIELDS);
        [$from] = ReportPeriod::monthRange($data['month']);

        $this->saveStructured('academic_monthly', $data['month'], 'Báo cáo tháng Học thuật — '.ReportPeriod::monthLabel($data['month']),
            $from->toDateString(), ['narrative' => $data['narrative']], $this->narrativeText($data['narrative'], self::MONTHLY_FIELDS));

        return back()->with('success', 'Đã lưu báo cáo '.mb_strtolower(ReportPeriod::monthLabel($data['month'])).'.');
    }

    /**
     * Báo cáo tháng của giáo viên: phần chung (tiến độ, khó khăn, đề xuất) + tình hình từng lớp đang dạy (học sinh cần chú ý,
     * giải pháp, cần hỗ trợ không). Kèm số liệu lớp trong tháng và order học thuật đã gửi để giáo viên tham chiếu.
     */
    public function teacherMonthly(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->redirectUnlessStructured('teacher_monthly')) {
            return $redirect;
        }
        $month = ReportPeriod::pick($request->input('month'), ReportPeriod::MONTH_PATTERN, ReportPeriod::currentMonth());
        $report = $this->structuredReport('teacher_monthly', $month);
        $quality = new TeachingQuality($month);

        return Inertia::render('Reports/TeacherMonthly', [
            'tabs' => $this->reportTabs(Auth::user(), 'teacher_monthly'),
            'month' => $month,
            'months' => ReportPeriod::monthOptions(),
            'generalFields' => self::TEACHER_GENERAL_FIELDS,
            'classFields' => self::TEACHER_CLASS_FIELDS,
            'general' => $report?->data['general'] ?? [],
            'classValues' => (object) ($report?->data['classes'] ?? []),
            'classes' => $quality->classMetrics($quality->classesOf(Auth::user())),
            'orders' => $this->academicOrders([Auth::id()], $quality),
            'submittedAt' => $report?->updated_at?->toIso8601String(),
            'history' => $this->structuredHistory('teacher_monthly', fn (string $key) => ReportPeriod::monthLabel($key), 'month'),
        ]);
    }

    public function teacherMonthlyStore(Request $request): RedirectResponse
    {
        $this->guardStructured('teacher_monthly');
        $data = $request->validate([
            'month' => ['required', 'regex:'.ReportPeriod::MONTH_PATTERN],
            'general' => ['nullable', 'array'],
            ...collect(self::TEACHER_GENERAL_FIELDS)->keys()->mapWithKeys(fn ($f) => ["general.{$f}" => ['nullable', 'string', 'max:10000']])->all(),
            'classes' => ['nullable', 'array'],
            ...collect(self::TEACHER_CLASS_FIELDS)->keys()->mapWithKeys(fn ($f) => ["classes.*.{$f}" => ['nullable', 'string', 'max:5000']])->all(),
            'classes.*.need_support' => ['nullable', 'boolean'],
            'classes.*.support_note' => ['nullable', 'string', 'max:5000'],
        ]);

        // Chỉ nhận lớp giáo viên đang giữ trong tháng báo cáo.
        $quality = new TeachingQuality($data['month']);
        $classes = $quality->classesOf(Auth::user())->keyBy('id');
        $general = collect(self::TEACHER_GENERAL_FIELDS)->keys()
            ->mapWithKeys(fn ($f) => [$f => filled($data['general'][$f] ?? null) ? $data['general'][$f] : null])->all();
        $classData = collect($data['classes'] ?? [])
            ->filter(fn ($row, $id) => $classes->has((int) $id))
            ->map(fn (array $row) => [
                ...collect(self::TEACHER_CLASS_FIELDS)->keys()->mapWithKeys(fn ($f) => [$f => filled($row[$f] ?? null) ? $row[$f] : null])->all(),
                'need_support' => (bool) ($row['need_support'] ?? false),
                'support_note' => filled($row['support_note'] ?? null) ? $row['support_note'] : null,
            ])
            ->filter(fn (array $row) => $row['need_support'] || collect($row)->except('need_support')->filter()->isNotEmpty())
            ->all();
        if (collect($general)->filter()->isEmpty() && $classData === []) {
            throw ValidationException::withMessages(['general' => 'Nhập ít nhất một mục của báo cáo.']);
        }

        $lines = [$this->narrativeText($general, self::TEACHER_GENERAL_FIELDS)];
        foreach ($classData as $id => $row) {
            $lines[] = 'Lớp '.$classes->get((int) $id)->name.":\n".collect(self::TEACHER_CLASS_FIELDS)
                ->filter(fn ($label, $f) => filled($row[$f]))->map(fn ($label, $f) => '- '.$label.': '.$row[$f])
                ->push('- Cần hỗ trợ: '.($row['need_support'] ? 'Có'.($row['support_note'] ? ' — '.$row['support_note'] : '') : 'Không'))
                ->implode("\n");
        }
        [$from] = ReportPeriod::monthRange($data['month']);
        $this->saveStructured('teacher_monthly', $data['month'], 'Báo cáo tháng giáo viên — '.ReportPeriod::monthLabel($data['month']),
            $from->toDateString(), ['general' => $general, 'classes' => $classData], trim(implode("\n\n", array_filter($lines))));

        return back()->with('success', 'Đã lưu báo cáo '.mb_strtolower(ReportPeriod::monthLabel($data['month'])).'.');
    }

    /**
     * Order học thuật của giáo viên trong tháng (tạo trong tháng hoặc dùng trong tháng).
     *
     * @param  list<int>  $userIds
     * @return list<array<string, mixed>>
     */
    public static function academicOrders(array $userIds, TeachingQuality $quality, int $limit = 20): array
    {
        return MaterialOrder::with(['requester:id,name', 'classRoom:id,name'])
            ->whereIn('requester_id', $userIds)
            ->where('category', MaterialOrder::CATEGORY_ACADEMIC)
            ->where(fn ($q) => $q->whereBetween('created_at', [$quality->from, $quality->to])
                ->orWhereBetween('use_date', [$quality->from->toDateString(), $quality->to->toDateString()]))
            ->latest()->limit($limit)->get()
            ->map(fn (MaterialOrder $o) => [
                'id' => $o->id,
                'code' => $o->code,
                'title' => $o->title,
                'teacher' => $o->requester?->name,
                'class' => $o->classRoom?->name,
                'use_date' => $o->use_date?->toDateString(),
                'status_label' => MaterialOrder::STATUSES[$o->status] ?? $o->status,
                'open' => in_array($o->status, MaterialOrder::OPEN_STATUSES, true),
            ])->values()->all();
    }

    /** Báo cáo quý Học thuật: tường thuật + 3 báo cáo tháng trong quý. */
    public function academicQuarterly(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->redirectUnlessStructured('academic_quarterly')) {
            return $redirect;
        }
        $quarter = ReportPeriod::pick($request->input('quarter'), ReportPeriod::QUARTER_PATTERN, ReportPeriod::currentQuarter());
        $report = $this->structuredReport('academic_quarterly', $quarter);
        $monthly = StaffReport::where('user_id', Auth::id())->where('type', 'monthly')
            ->whereIn('period_key', ReportPeriod::quarterMonths($quarter))->get()->keyBy('period_key');

        return Inertia::render('Reports/AcademicQuarterly', [
            'tabs' => $this->reportTabs(Auth::user(), 'academic_quarterly'),
            'quarter' => $quarter,
            'quarterLabel' => ReportPeriod::quarterLabel($quarter),
            'quarters' => ReportPeriod::quarterOptions(),
            'sections' => self::QUARTERLY_SECTIONS,
            'values' => $report?->data['narrative'] ?? [],
            'submittedAt' => $report?->updated_at?->toIso8601String(),
            'monthlyReports' => collect(ReportPeriod::quarterMonths($quarter))->map(fn (string $m) => [
                'month' => $m,
                'label' => 'Báo cáo '.mb_strtolower(ReportPeriod::monthLabel($m)),
                'submitted' => $monthly->has($m),
                'updated_at' => $monthly->get($m)?->updated_at?->toIso8601String(),
            ])->values(),
            'history' => $this->structuredHistory('academic_quarterly', fn (string $key) => ReportPeriod::quarterLabel($key), 'quarter'),
        ]);
    }

    public function academicQuarterlyStore(Request $request): RedirectResponse
    {
        $this->guardStructured('academic_quarterly');
        $fields = array_merge(...array_values(self::QUARTERLY_SECTIONS));
        $data = $this->validatedNarrative($request, 'quarter', ReportPeriod::QUARTER_PATTERN, $fields);
        [$from] = ReportPeriod::quarterRange($data['quarter']);

        $this->saveStructured('academic_quarterly', $data['quarter'], 'Báo cáo quý Học thuật — '.ReportPeriod::quarterLabel($data['quarter']),
            $from->toDateString(), ['narrative' => $data['narrative']], $this->narrativeText($data['narrative'], $fields));

        return back()->with('success', 'Đã lưu báo cáo quý '.$data['quarter'].'.');
    }

    /** Mở màn báo cáo không thuộc vai trò mình (link cũ / mockup): quay về báo cáo định kỳ chính thay vì báo lỗi. */
    private function redirectUnlessStructured(string $kind): ?RedirectResponse
    {
        $this->guard();

        return in_array($kind, StaffType::structuredReports(Auth::user()), true)
            ? null
            : redirect()->route('reports.my')->with('info', self::STRUCTURED[$kind]['label'].' chỉ dành cho '.self::STRUCTURED[$kind]['for'].'.');
    }

    private function guardStructured(string $kind): void
    {
        $this->guard();
        abort_unless(in_array($kind, StaffType::structuredReports(Auth::user()), true), 403, 'Báo cáo này không thuộc vai trò của bạn.');
    }

    private function structuredReport(string $kind, string $period): ?StaffReport
    {
        return StaffReport::where('user_id', Auth::id())
            ->where('type', self::STRUCTURED[$kind]['type'])
            ->where('period_key', $period)
            ->first();
    }

    /** @param array<string, mixed> $data */
    private function saveStructured(string $kind, string $period, string $title, string $reportDate, array $data, string $content): void
    {
        StaffReport::updateOrCreate(
            ['user_id' => Auth::id(), 'type' => self::STRUCTURED[$kind]['type'], 'period_key' => $period],
            ['title' => $title, 'report_date' => $reportDate, 'data' => $data, 'content' => $content, 'status' => 'submitted'],
        );
    }

    /**
     * Các kỳ đã nộp (mới nhất trước) để chuyển nhanh sang xem / sửa.
     *
     * @return list<array{period: string, label: string, href: string, updated_at: string|null}>
     */
    private function structuredHistory(string $kind, \Closure $label, string $param): array
    {
        return StaffReport::where('user_id', Auth::id())
            ->where('type', self::STRUCTURED[$kind]['type'])
            ->whereNotNull('period_key')
            ->orderByDesc('period_key')->limit(12)->get(['period_key', 'updated_at'])
            ->map(fn (StaffReport $r) => [
                'period' => $r->period_key,
                'label' => $label($r->period_key),
                'href' => route(self::STRUCTURED[$kind]['route'], [$param => $r->period_key]),
                'updated_at' => $r->updated_at?->toIso8601String(),
            ])->values()->all();
    }

    /**
     * @param  array<string, string>  $fields
     * @return array{narrative: array<string, string|null>}
     */
    private function validatedNarrative(Request $request, string $periodField, string $pattern, array $fields): array
    {
        $data = $request->validate([
            $periodField => ['required', 'regex:'.$pattern],
            'narrative' => ['required', 'array'],
            ...collect($fields)->keys()->mapWithKeys(fn ($f) => ["narrative.{$f}" => ['nullable', 'string', 'max:10000']])->all(),
        ], ['narrative.required' => 'Nhập ít nhất một mục của báo cáo.']);

        $narrative = collect($fields)->keys()->mapWithKeys(fn ($f) => [$f => filled($data['narrative'][$f] ?? null) ? $data['narrative'][$f] : null])->all();
        if (collect($narrative)->filter()->isEmpty()) {
            throw ValidationException::withMessages(['narrative' => 'Nhập ít nhất một mục của báo cáo.']);
        }

        return [$periodField => $data[$periodField], 'narrative' => $narrative];
    }

    /** @param array<string, string> $fields */
    private function narrativeText(array $narrative, array $fields): string
    {
        return collect($narrative)->filter()->map(fn ($text, $f) => $fields[$f].":\n".$text)->implode("\n\n");
    }

    /**
     * Tab của màn "Báo cáo định kỳ của tôi": báo cáo định kỳ chính + báo cáo có cấu trúc theo vai trò.
     * Không có báo cáo có cấu trúc → không hiện thanh tab.
     *
     * @return list<array{label: string, href: string, active: bool}>
     */
    private function reportTabs(User $user, ?string $current): array
    {
        $structured = StaffType::structuredReports($user);
        if ($structured === []) {
            return [];
        }

        return [
            ['label' => StaffReport::TYPE_LABELS[$this->primaryType($user)] ?? 'Báo cáo', 'href' => route('reports.my'), 'active' => $current === null],
            ...array_map(fn (string $kind) => [
                'label' => self::STRUCTURED[$kind]['label'],
                'href' => route(self::STRUCTURED[$kind]['route']),
                'active' => $current === $kind,
            ], $structured),
        ];
    }

    // ───────────────────────── ADMIN XEM TỔNG ─────────────────────────
    public function allReports(Request $request): Response
    {
        $this->guard();
        abort_unless($this->isPrivileged(), 403, 'Chỉ Admin / Manager mới xem tổng hợp.');

        $query = StaffReport::with(['user', 'followups']);
        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }
        if ($date = $request->input('date')) {
            $query->whereDate('report_date', $date);
        }

        $reports = $query->latest('report_date')->latest('id')->paginate(20)->withQueryString()
            ->through(fn (StaffReport $r) => [
                'id' => $r->id,
                'type' => $r->type,
                'type_label' => $r->type_label,
                'severity_label' => $r->type === 'journal' ? $r->severity_label : null,
                'title' => $r->title,
                'content' => $r->content,
                'report_date' => $r->report_date->toDateString(),
                'user' => $r->user?->name,
                'followups_count' => $r->followups->count(),
            ]);

        $stats = [
            'journal' => StaffReport::where('type', 'journal')->count(),
            'daily' => StaffReport::where('type', 'daily')->count(),
            'weekly' => StaffReport::where('type', 'weekly')->count(),
            'monthly' => StaffReport::where('type', 'monthly')->count(),
            'urgent_open' => StaffReport::where('type', 'journal')->where('severity', 'urgent')->where('status', '!=', 'resolved')->count(),
        ];

        return Inertia::render('Reports/All', [
            'reports' => $reports,
            'stats' => $stats,
            'types' => Ui::options(StaffReport::TYPE_LABELS),
        ]);
    }
}
