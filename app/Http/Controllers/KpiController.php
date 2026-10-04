<?php

namespace App\Http\Controllers;

use App\Helpers\AclHelper;
use App\Models\ClassModel;
use App\Models\KpiCriterion;
use App\Models\KpiEvaluation;
use App\Models\KpiEvaluationItem;
use App\Models\PayrollPeriod;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\User;
use App\Support\DataScope;
use App\Support\StaffType;
use App\Support\StatusLabel;
use App\Support\Ui;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * KPI Học vụ: cấu hình chỉ số trọng số, đánh giá KPI theo tháng cho từng nhân
 * sự, và rà soát điểm danh (admin học vụ).
 */
class KpiController extends Controller
{
    /** Chức danh thuộc diện đánh giá KPI tháng (phân loại nhân sự, không phải phân quyền). */
    private const STAFF_ROLES = ['academic_staff', 'academic_lead', 'teacher', 'teacher_fulltime', 'teacher_parttime', 'assistant'];

    private function guard(string $permission = 'kpi.view'): void
    {
        abort_unless(Auth::user()?->can($permission), 403);
    }

    /**
     * Nhân sự người xem được xem / chấm KPI theo phạm vi "kpi.scope_*": Của tôi → chỉ mình; Chi nhánh → nhân sự thuộc
     * chi nhánh mình; Toàn hệ thống → mọi nhân sự.
     */
    private function scopedStaff(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        $viewer = Auth::user();

        return DataScope::apply(
            $query, $viewer, 'kpi',
            fn ($q) => $q->whereKey($viewer->id),
            fn ($q, array $branchIds) => $q->whereIn('branch_id', $branchIds)
                ->orWhereHas('branches', fn ($b) => $b->whereIn('branches.id', $branchIds)),
            branchIncludesOwn: true,
        );
    }

    // ───────────────────── CẤU HÌNH KPI ─────────────────────
    public function criteria(): InertiaResponse
    {
        $this->guard();
        $criteria = KpiCriterion::orderByDesc('is_active')->ordered()->get();
        $totalWeight = $criteria->where('is_active', true)->sum('weight');
        $fund = KpiCriterion::fund();
        $groups = $criteria->pluck('group_name')->filter()->unique()->values();
        $fmtWeight = fn ($w) => rtrim(rtrim(number_format((float) $w, 2), '0'), '.');

        return Inertia::render('Kpi/Criteria', [
            'groups' => $groups,
            'fund' => (float) $fund,
            'totalWeight' => (float) $totalWeight,
            'totalWeightLabel' => $fmtWeight($totalWeight),
            'criteriaGroups' => $criteria->groupBy(fn ($c) => $c->group_name ?: 'Chưa phân nhóm')
                ->map(fn ($items, $groupName) => [
                    'name' => $groupName,
                    'count' => $items->count(),
                    'active_fund' => (float) $items->where('is_active', true)->sum(fn ($c) => $c->fundAmount($fund)),
                    'items' => $items->map(fn (KpiCriterion $cr) => [
                        'id' => $cr->id,
                        'code' => $cr->code,
                        'name' => $cr->name,
                        'weight' => $fmtWeight($cr->weight),
                        'fund_amount' => (float) $cr->fundAmount($fund),
                        'threshold_full' => $cr->threshold_full,
                        'threshold_half' => $cr->threshold_half,
                        'is_active' => (bool) $cr->is_active,
                        'group_name' => $cr->group_name,
                        'target' => $cr->target,
                        'unit' => $cr->unit,
                        'description' => $cr->description,
                    ])->values(),
                ])->values(),
        ]);
    }

    public function criteriaStore(Request $request)
    {
        $this->guard('kpi.manage');
        $validated = $request->validate($this->criterionRules());
        $validated['is_active'] = true;
        $validated['sort_order'] = (int) KpiCriterion::max('sort_order') + 1;
        KpiCriterion::create($validated);

        return back()->with('success', 'Đã thêm chỉ số KPI!');
    }

    public function criteriaUpdate(Request $request, int $id)
    {
        $this->guard('kpi.manage');
        $criterion = KpiCriterion::findOrFail($id);
        $validated = $request->validate($this->criterionRules() + ['is_active' => 'nullable|boolean']);
        $validated['is_active'] = $request->boolean('is_active');
        $criterion->update($validated);

        return back()->with('success', 'Đã cập nhật chỉ số KPI!');
    }

    /** Mục KPI Học vụ: nhóm (1 trong 6 nhóm), mã (1.1…), trọng số % quỹ, ngưỡng đạt 100% / 50%. */
    private function criterionRules(): array
    {
        return [
            'group_name' => 'nullable|string|max:255',
            'code' => 'nullable|string|max:10',
            'name' => 'required|string|max:255',
            'weight' => 'required|numeric|min:0|max:100',
            'target' => 'nullable|string|max:255',
            'threshold_full' => 'nullable|string|max:255',
            'threshold_half' => 'nullable|string|max:255',
            'unit' => 'nullable|string|max:50',
            'description' => 'nullable|string',
        ];
    }

    public function criteriaDestroy(int $id)
    {
        $this->guard('kpi.manage');
        KpiCriterion::findOrFail($id)->delete();

        return back()->with('success', 'Đã xoá chỉ số KPI!');
    }

    // ───────────────────── ĐÁNH GIÁ KPI THÁNG ─────────────────────
    public function monthly(Request $request): InertiaResponse
    {
        $this->guard();
        [$month, $year] = $this->monthYear($request);

        $evaluations = KpiEvaluation::with(['user', 'evaluator'])
            ->where('month', $month)->where('year', $year)
            ->get()
            ->keyBy('user_id');

        $search = trim((string) $request->query('search', ''));
        $role = in_array($request->query('role'), self::STAFF_ROLES, true) ? $request->query('role') : null;
        $staff = $this->scopedStaff(User::whereHas('roles', fn ($q) => $q->whereIn('name', $role ? [$role] : self::STAFF_ROLES)))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('employee_code', 'like', "%{$search}%")))
            ->with('roles')->orderBy('name')->paginate($request->perPage(20))->withQueryString();
        $fund = KpiCriterion::fund();
        $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
        $periodValue = sprintf('%04d-%02d', $year, $month);
        $periodOptions = collect(range(0, 11))->mapWithKeys(fn ($i) => [now()->startOfMonth()->subMonths($i)->format('Y-m') => 'Tháng '.now()->startOfMonth()->subMonths($i)->format('m/Y')])
            ->put($periodValue, 'Tháng '.sprintf('%02d/%04d', $month, $year))->sortKeysDesc();
        $roleOptions = collect(self::STAFF_ROLES)->mapWithKeys(fn ($r) => [$r => AclHelper::roleLabel($r)]);

        return Inertia::render('Kpi/Monthly', [
            'staff' => $staff->through(function (User $s) use ($evaluations, $fund, $fmt, $month, $year) {
                $eval = $evaluations->get($s->id);
                $isHv = StaffType::usesAcademicStaffKpi($s);
                [$grade, $gradeLabel] = $eval ? KpiEvaluation::gradeFor((float) $eval->total_score) : [null, null];

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'code' => $s->employee_code ?: $s->email,
                    'role' => AclHelper::roleLabel((string) $s->getRoleNames()->first()),
                    'evaluated' => (bool) $eval,
                    'score' => $eval ? (float) $eval->total_score : null,
                    'score_label' => $eval ? $fmt($eval->total_score).'%' : '—',
                    'grade' => $grade,
                    'grade_label' => $gradeLabel,
                    'kpi_amount' => $isHv && $eval ? round($fund * (float) $eval->total_score / 100) : null,
                    'status' => $eval?->status,
                    'evaluate_url' => route('kpi.evaluate', ['userId' => $s->id, 'month' => $month, 'year' => $year], false),
                ];
            }),
            'month' => $month,
            'year' => $year,
            'periodValue' => $periodValue,
            'periodOptions' => Ui::options($periodOptions),
            'roleOptions' => Ui::options($roleOptions),
        ]);
    }

    /** Tháng/năm từ ?period=YYYY-MM (ô chọn kỳ theo mockup) hoặc ?month=&year=. */
    private function monthYear(Request $request): array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', (string) $request->input('period'), $m)) {
            return [min(12, max(1, (int) $m[2])), (int) $m[1]];
        }

        return [min(12, max(1, (int) $request->input('month', now()->month))), (int) $request->input('year', now()->year)];
    }

    /**
     * Phiếu KPI tháng của một nhân sự (mockup 04_kpi_thang + 03_tong_hop_kpi_danh_gia_thang): bảng 6 nhóm / 15 mục
     * (Quỹ, Ngưỡng 100 / 50, Thực tế, % Đạt, Tiền KPI, Lỗi nghiêm trọng), tổng hợp theo nhóm, xếp loại tháng,
     * cảnh báo hiệu suất, nhận xét của quản lý, "Chốt KPI tháng".
     */
    public function evaluate(Request $request, int $userId): InertiaResponse
    {
        $this->guard();
        $staff = $this->scopedStaff(User::query())->findOrFail($userId);
        [$month, $year] = $this->monthYear($request);

        $criteria = KpiCriterion::active()->ordered()->get();
        $fund = KpiCriterion::fund();
        $isAcademicStaff = StaffType::usesAcademicStaffKpi($staff);
        $evaluation = KpiEvaluation::with(['items', 'evaluator'])
            ->where('user_id', $userId)->where('month', $month)->where('year', $year)->first();
        $scores = $evaluation ? $evaluation->items->keyBy('kpi_criterion_id') : collect();
        $isSelf = $userId === (int) $request->user()->id;

        // Tổng hợp theo nhóm: quỹ nhóm, tiền đạt (theo trọng số chuẩn hóa như bảng lương), % đạt.
        $weightTotal = (float) $criteria->sum('weight');
        $groupSummary = $criteria->groupBy(fn ($c) => $c->group_name ?: 'Chưa phân nhóm')->map(function ($items) use ($scores, $fund, $weightTotal) {
            $groupFund = $weightTotal > 0 ? $items->sum(fn ($c) => $fund * (float) $c->weight / $weightTotal) : 0;
            $earned = $weightTotal > 0 ? $items->sum(fn ($c) => $fund * (float) $c->weight / $weightTotal * (float) ($scores->get($c->id)?->score ?? 0) / 100) : 0;

            return ['count' => $items->count(), 'fund' => round($groupFund), 'earned' => round($earned), 'percent' => $groupFund > 0 ? round($earned / $groupFund * 100, 1) : 0];
        });
        $warnings = [
            'low' => $criteria->filter(fn ($c) => ($s = $scores->get($c->id)) && (float) $s->score > 0 && (float) $s->score <= 50)->count(),
            'zero' => $criteria->filter(fn ($c) => ($s = $scores->get($c->id)) && (float) $s->score <= 0)->count(),
        ];
        $staffOptions = $this->scopedStaff(User::whereHas('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES)))->orderBy('name')->get(['id', 'name']);

        $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
        $total = (float) ($evaluation?->total_score ?? 0);
        [$grade, $gradeLabel, $gradeRange] = KpiEvaluation::gradeFor($total);
        $itemFund = fn ($c) => $weightTotal > 0 ? $fund * (float) $c->weight / $weightTotal : 0;
        $periodValue = sprintf('%04d-%02d', $year, $month);
        $periodOptions = collect(range(0, 11))->mapWithKeys(fn ($i) => [now()->startOfMonth()->subMonths($i)->format('Y-m') => 'Tháng '.now()->startOfMonth()->subMonths($i)->format('m / Y')])
            ->put($periodValue, 'Tháng '.sprintf('%02d / %04d', $month, $year))->sortKeysDesc();

        return Inertia::render('Kpi/Evaluate', [
            'staff' => [
                'id' => $staff->id,
                'name' => $staff->name,
                'role_label' => $isAcademicStaff ? 'Học vụ' : AclHelper::roleLabel((string) $staff->getRoleNames()->first()),
            ],
            'month' => $month,
            'year' => $year,
            'periodValue' => $periodValue,
            'periodOptions' => Ui::options($periodOptions),
            'staffOptions' => Ui::options($staffOptions, 'name'),
            'isSelf' => $isSelf,
            'canConfirm' => $request->user()->can('kpi.confirm') && ! $isSelf,
            'isAcademicStaff' => $isAcademicStaff,
            // Chốt KPI chỉ từ ngày cuối tháng (chủ dự án chốt); trước đó chỉ lưu nháp.
            'kpiCloseOn' => \Illuminate\Support\Carbon::create($year, $month, 1)->endOfMonth()->format('d/m/Y'),
            'canClose' => now()->gte(\Illuminate\Support\Carbon::create($year, $month, 1)->endOfMonth()->startOfDay()),
            'fund' => (float) $fund,
            'evaluation' => $evaluation ? [
                'status' => $evaluation->status,
                'evaluator' => $evaluation->evaluator?->name,
                'strengths' => $evaluation->strengths,
                'improvements' => $evaluation->improvements,
                'next_actions' => $evaluation->next_actions,
                'comment' => $evaluation->comment,
            ] : null,
            'total' => $total,
            'totalLabel' => $fmt($total),
            'grade' => ['letter' => $grade, 'label' => $gradeLabel, 'range' => $gradeRange],
            'criteriaGroups' => $criteria->groupBy(fn ($c) => $c->group_name ?: 'Chưa phân nhóm')
                ->map(fn ($items, $groupName) => [
                    'name' => $groupName,
                    'items' => $items->map(function (KpiCriterion $cr) use ($scores, $itemFund) {
                        $item = $scores->get($cr->id);

                        return [
                            'id' => $cr->id,
                            'code' => $cr->code,
                            'name' => $cr->name,
                            'description' => $cr->description,
                            'fund' => round($itemFund($cr)),
                            'fund_exact' => (float) $itemFund($cr),
                            'threshold_full' => $cr->threshold_full ?: ($cr->target ?: '—'),
                            'threshold_half' => $cr->threshold_half ?: '—',
                            'actual' => $item?->actual,
                            'score' => $item?->score !== null ? rtrim(rtrim(number_format($item->score, 2, '.', ''), '0'), '.') : '',
                            'critical' => (bool) $item?->critical_error,
                        ];
                    })->values(),
                ])->values(),
            'groupSummary' => $groupSummary->map(fn ($row, $groupName) => $row + ['name' => $groupName, 'percent_label' => $fmt($row['percent'])])->values(),
            'warnings' => $warnings,
        ]);
    }

    public function evaluateStore(Request $request, int $userId)
    {
        $this->guard('kpi.confirm');
        $this->scopedStaff(User::query())->findOrFail($userId);
        // Nhân viên không tự chấm KPI của chính mình (A3 / Phase 3)
        abort_if($userId === (int) $request->user()->id, 403, 'Bạn không được tự chấm KPI của chính mình.');
        $validated = $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2100',
            'comment' => 'nullable|string',
            'score' => 'required|array',
            'score.*' => 'nullable|numeric|min:0|max:100',
            'note' => 'nullable|array',
            'actual' => 'nullable|array',
            'actual.*' => 'nullable|string|max:255',
            'critical' => 'nullable|array',
            'strengths' => 'nullable|string|max:2000',
            'improvements' => 'nullable|string|max:2000',
            'next_actions' => 'nullable|string|max:2000',
            // "Lưu nháp" không dùng cho bảng lương; mặc định (và "Chốt KPI tháng") = đã chốt.
            'action' => 'nullable|in:draft,confirm',
        ]);
        // Kỳ lương của tháng đã duyệt / đã chi trả thì khóa toàn bộ dữ liệu lương, gồm đánh giá KPI tháng đó.
        $monthStart = \Illuminate\Support\Carbon::create((int) $validated['year'], (int) $validated['month'], 1);
        if (PayrollPeriod::isLockedFor($monthStart) || PayrollPeriod::isLockedFor($monthStart->copy()->endOfMonth())) {
            $message = 'Kỳ lương tháng '.$monthStart->format('m/Y').' đã duyệt — không thể sửa đánh giá KPI của tháng này.';

            return back()->withInput()->withErrors(['month' => $message])->with('error', $message);
        }

        // Chủ dự án chốt: KPI chốt vào ngày cuối tháng — chưa tới ngày đó chỉ được lưu nháp, không chốt (confirm).
        $kpiCloseOn = $monthStart->copy()->endOfMonth()->startOfDay();
        if (($validated['action'] ?? 'confirm') !== 'draft' && now()->lt($kpiCloseOn)) {
            $message = 'Chốt KPI từ ngày cuối tháng '.$kpiCloseOn->format('d/m').' — hiện chỉ được lưu nháp.';

            return back()->withInput()->withErrors(['month' => $message])->with('error', $message);
        }

        $critical = collect($validated['critical'] ?? [])->filter()->keys()->map(fn ($id) => (int) $id)->all();
        // "Lỗi nghiêm trọng" đưa % đạt của mục về 0.
        foreach ($critical as $criterionId) {
            $validated['score'][$criterionId] = 0;
        }

        $criteria = KpiCriterion::active()->get()->keyBy('id');

        // Điểm tổng theo trọng số: Σ(điểm × trọng số) / Σ trọng số CÁC MỤC ĐANG ÁP DỤNG (mục chưa chấm = 0) —
        // cùng cách tính với KPI Học vụ trên bảng lương (quỹ × điểm tổng %).
        $weightedSum = 0;
        $weightTotal = (float) $criteria->sum('weight');
        foreach ($validated['score'] as $criterionId => $score) {
            if (! isset($criteria[$criterionId]) || $score === null || $score === '') {
                continue;
            }
            $weightedSum += (float) $score * (float) $criteria[$criterionId]->weight;
        }
        $total = $weightTotal > 0 ? round($weightedSum / $weightTotal, 2) : 0;

        $evaluation = KpiEvaluation::updateOrCreate(
            ['user_id' => $userId, 'month' => $validated['month'], 'year' => $validated['year']],
            [
                'evaluator_id' => Auth::id(),
                'total_score' => $total,
                'comment' => $validated['comment'] ?? null,
                'strengths' => $validated['strengths'] ?? null,
                'improvements' => $validated['improvements'] ?? null,
                'next_actions' => $validated['next_actions'] ?? null,
                'status' => ($validated['action'] ?? 'confirm') === 'draft' ? 'draft' : 'confirmed',
            ]
        );

        foreach ($validated['score'] as $criterionId => $score) {
            if (! isset($criteria[$criterionId])) {
                continue;
            }
            if ($score === null || $score === '') {
                // Ô để trống: tổng điểm tính mục này = 0 → đưa điểm cũ (nếu có) về 0 cho khớp, không để điểm cũ còn hiện.
                KpiEvaluationItem::where('kpi_evaluation_id', $evaluation->id)->where('kpi_criterion_id', $criterionId)->update(['score' => 0]);

                continue;
            }
            KpiEvaluationItem::updateOrCreate(
                ['kpi_evaluation_id' => $evaluation->id, 'kpi_criterion_id' => $criterionId],
                [
                    'score' => $score, 'note' => $validated['note'][$criterionId] ?? null,
                    'actual' => $validated['actual'][$criterionId] ?? null,
                    'critical_error' => in_array((int) $criterionId, $critical, true),
                ]
            );
        }

        $label = $evaluation->status === 'draft' ? 'Đã lưu nháp đánh giá KPI' : 'Đã chốt KPI tháng';

        return redirect()->route('kpi.monthly', ['month' => $validated['month'], 'year' => $validated['year']])
            ->with('success', "{$label} (Tổng điểm: {$total}%).");
    }

    // ───────────────────── RÀ SOÁT ĐIỂM DANH (Admin học vụ) ─────────────────────
    public function attendanceReview(Request $request): InertiaResponse
    {
        $this->guard();
        $date = $request->input('date', now()->toDateString());
        $classId = $request->input('class_id');

        // Chỉ điểm danh của lớp trong phạm vi dữ liệu Lớp học của người xem (Chi nhánh / Của tôi / Toàn hệ thống).
        $visibleClassIds = ClassModel::query()->visibleTo($request->user())->select('id');
        $query = StudentAttendance::with(['student', 'classModel', 'teacher'])
            ->whereIn('class_id', $visibleClassIds)
            ->whereDate('session_date', $date);
        if ($classId) {
            $query->where('class_id', $classId);
        }
        $records = $query->latest('id')->get();

        $summary = [
            'present' => $records->where('status', 'present')->count(),
            'late' => $records->where('status', 'late')->count(),
            'absent' => $records->where('status', 'absent')->count(),
            'excused' => $records->where('status', 'excused')->count(),
        ];

        $classes = ClassModel::query()->visibleTo($request->user())->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Kpi/AttendanceReview', [
            'records' => $records->map(fn (StudentAttendance $r) => [
                'id' => $r->id,
                'student' => $r->student?->name,
                'class' => $r->classModel?->name,
                'teacher' => $r->teacher?->name ?? '—',
                'note' => $r->note,
                'status' => $r->status,
                'status_label' => $r->status_label,
                'review_status' => $r->review_status,
                'review_label' => StatusLabel::for($r->review_status, 'Chưa rà soát'),
            ])->values(),
            'summary' => $summary,
            'classes' => Ui::options($classes, 'name'),
            'date' => $date,
            'classId' => $classId,
        ]);
    }

    public function reviewAttendance(Request $request, int $id)
    {
        $this->guard('kpi.confirm');
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $attendance = StudentAttendance::query()
            ->whereIn('class_id', ClassModel::query()->visibleTo($request->user())->select('id'))
            ->findOrFail($id);
        $attendance->update([
            'review_status' => $validated['decision'],
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
            'review_note' => $validated['review_note'] ?? null,
        ]);

        $student = Student::find($attendance->student_id);
        if ($student) {
            $approved = StudentAttendance::where('student_id', $student->id)->where('review_status', 'approved');
            $student->update([
                'attended_lessons' => (clone $approved)->whereIn('status', ['present', 'late'])->count(),
                'total_lessons' => max($student->total_lessons, (clone $approved)->count()),
            ]);
        }

        return redirect()->back()->with('success', $validated['decision'] === 'approved' ? 'Đã duyệt điểm danh.' : 'Đã trả lại điểm danh để chỉnh sửa.');
    }
}
