<?php

namespace App\Http\Controllers;

use App\Helpers\AclHelper;
use App\Http\Concerns\RendersModals;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\KpiCriterion;
use App\Models\KpiEvaluation;
use App\Models\KpiEvaluationItem;
use App\Models\PayrollPeriod;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\User;
use App\Services\Kpi\KpiSheetService;
use App\Support\DataScope;
use App\Support\Money;
use App\Support\Roles;
use App\Support\StatusLabel;
use App\Support\Ui;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * KPI Học vụ: cấu hình chỉ số trọng số, đánh giá KPI theo tháng cho từng nhân
 * sự, và rà soát điểm danh (admin học vụ).
 */
class KpiController extends Controller
{
    use RendersModals;

    /** Chức danh thuộc diện đánh giá KPI tháng (phân loại nhân sự, không phải phân quyền). */
    private const STAFF_ROLES = Roles::KPI_ROLES;

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

    // ───────────────────── TIÊU CHÍ KPI THEO VAI TRÒ ─────────────────────
    /** Vai trò đang xem trên màn Tiêu chí KPI (?role=), mặc định Học vụ. */
    private function criteriaRole(Request $request): string
    {
        $role = (string) $request->input('role');

        return in_array($role, KpiCriterion::ROLES, true) ? $role : Roles::ACADEMIC_STAFF;
    }

    public function criteria(Request $request): InertiaResponse
    {
        $this->guard();
        $role = $this->criteriaRole($request);
        $criteria = KpiCriterion::forRole($role)->orderByDesc('is_active')->ordered()->get();
        $totalWeight = (float) $criteria->where('is_active', true)->sum('weight');
        // Quỹ KPI (đ) chỉ có ở Học vụ (Cấu hình tham số lương); vai trò khác chấm theo % đạt.
        $fund = $role === Roles::ACADEMIC_STAFF ? KpiCriterion::fund() : null;
        $fmtWeight = fn ($w) => rtrim(rtrim(number_format((float) $w, 2), '0'), '.');

        return Inertia::render('Kpi/Criteria', [
            'role' => $role,
            'roleOptions' => Ui::options(collect(KpiCriterion::ROLES)->mapWithKeys(fn ($r) => [$r => AclHelper::roleLabel($r)])),
            'unitOptions' => Ui::options(collect(KpiCriterion::UNITS)->map(fn ($label) => \Illuminate\Support\Str::ucfirst($label))),
            'sourceOptions' => Ui::options(collect(['' => 'Người chấm điền tay'])->merge(collect(KpiCriterion::AUTO_SOURCES)->mapWithKeys(fn ($l, $k) => [$k => 'Tự động: '.$l]))),
            'groups' => $criteria->pluck('group_name')->filter()->unique()->values(),
            'fund' => $fund,
            'totalWeight' => $totalWeight,
            'totalWeightLabel' => $fmtWeight($totalWeight),
            'criteriaGroups' => $criteria->groupBy(fn ($c) => $c->group_name ?: 'Chưa phân nhóm')
                ->map(fn ($items, $groupName) => [
                    'name' => $groupName,
                    'count' => $items->count(),
                    'active_weight' => $fmtWeight($items->where('is_active', true)->sum('weight')),
                    'active_fund' => $fund !== null ? (float) $items->where('is_active', true)->sum(fn ($c) => $c->fundAmount($fund)) : null,
                    'items' => $items->map(fn (KpiCriterion $cr) => [
                        'id' => $cr->id,
                        'name' => $cr->name,
                        'group_name' => $cr->group_name,
                        'weight' => $fmtWeight($cr->weight),
                        'fund_amount' => $fund !== null ? (float) $cr->fundAmount($fund) : null,
                        'unit' => $cr->unit,
                        'auto_source' => $cr->isAuto() ? $cr->auto_source : null,
                        'max_full' => $cr->max_full,
                        'max_half' => $cr->max_half,
                        'threshold_full' => $cr->threshold_full,
                        'threshold_half' => $cr->threshold_half,
                        'description' => $cr->description,
                        'is_active' => (bool) $cr->is_active,
                    ])->values(),
                ])->values(),
        ]);
    }

    public function criteriaStore(Request $request)
    {
        $this->guard('kpi.manage');
        $validated = $request->validate($this->criterionRules() + ['role' => ['required', \Illuminate\Validation\Rule::in(KpiCriterion::ROLES)]]);
        $validated = $this->normalizeCriterion($validated);
        $validated['is_active'] = true;
        $validated['sort_order'] = (int) KpiCriterion::forRole($validated['role'])->max('sort_order') + 1;
        KpiCriterion::create($validated);

        return redirect()->route('kpi.criteria', ['role' => $validated['role']])->with('success', 'Đã thêm tiêu chí KPI.');
    }

    public function criteriaUpdate(Request $request, int $id)
    {
        $this->guard('kpi.manage');
        $criterion = KpiCriterion::findOrFail($id);
        $validated = $request->validate($this->criterionRules() + ['is_active' => 'nullable|boolean']);
        $validated['is_active'] = $request->boolean('is_active');
        $criterion->update($this->normalizeCriterion($validated));

        return redirect()->route('kpi.criteria', ['role' => $criterion->role])->with('success', 'Đã cập nhật tiêu chí KPI.');
    }

    /**
     * Tiêu chí KPI (dữ liệu chuẩn hóa để tính KPI): nhóm, tên, trọng số % quỹ, đơn vị đếm chọn từ danh sách, số lần tối đa
     * để đạt 100% / 50% (đếm lỗi, càng ít càng tốt; vượt ngưỡng 50% = 0%).
     */
    private function criterionRules(): array
    {
        return [
            'group_name' => 'nullable|string|max:255',
            'new_group' => 'nullable|string|max:255',
            'name' => 'required|string|max:255',
            'weight' => 'required|numeric|min:0|max:100',
            'unit' => ['required', \Illuminate\Validation\Rule::in(array_keys(KpiCriterion::UNITS))],
            'max_full' => 'required|integer|min:0|max:9999',
            'max_half' => 'required|integer|min:0|max:9999|gte:max_full',
            'description' => 'nullable|string|max:2000',
            'auto_source' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(KpiCriterion::AUTO_SOURCES))],
        ];
    }

    /** Nhóm mới (nếu gõ) thay nhóm chọn; nhãn "Ngưỡng 100 / 50" tự sinh từ số + đơn vị (không nhập tay hai lần). */
    private function normalizeCriterion(array $validated): array
    {
        $newGroup = trim((string) ($validated['new_group'] ?? ''));
        if ($newGroup !== '') {
            $validated['group_name'] = $newGroup;
        }
        unset($validated['new_group']);
        $validated['auto_source'] = ($validated['auto_source'] ?? null) ?: null;
        $validated['threshold_full'] = KpiCriterion::thresholdLabel((int) $validated['max_full'], $validated['unit']);
        $validated['threshold_half'] = KpiCriterion::thresholdLabel((int) $validated['max_half'], $validated['unit']);

        return $validated;
    }

    public function criteriaDestroy(int $id)
    {
        $this->guard('kpi.manage');
        $criterion = KpiCriterion::findOrFail($id);
        $criterion->delete();

        return redirect()->route('kpi.criteria', ['role' => $criterion->role])->with('success', 'Đã xoá tiêu chí KPI.');
    }

    // ───────────────────── PHIẾU KPI THÁNG ─────────────────────
    /**
     * Phiếu KPI tháng: mỗi nhân sự (vai trò có tiêu chí KPI) một dòng, lọc theo kỳ lương / vai trò / cơ sở. Bấm dòng mở phiếu
     * (modal) để điền số tiêu chí điền tay rồi Duyệt / Không duyệt. Phiếu tự tạo đầu tháng (kpi:create-sheets).
     */
    public function monthly(Request $request, KpiSheetService $sheets): InertiaResponse
    {
        $this->guard();
        [$month, $year] = $this->monthYear($request);
        $role = in_array($request->query('role'), self::STAFF_ROLES, true) ? $request->query('role') : null;
        $scopeBranchIds = DataScope::branchIds($request->user(), 'kpi');
        $branches = Branch::query()->when($scopeBranchIds !== null, fn ($q) => $q->whereIn('id', $scopeBranchIds))->orderBy('name')->get(['id', 'name']);
        $branchId = $branches->contains('id', (int) $request->query('branch_id')) ? (int) $request->query('branch_id') : null;

        $evaluations = KpiEvaluation::with('items')->where('month', $month)->where('year', $year)->get()->keyBy('user_id');
        $staff = $this->scopedStaff($sheets->staffQuery($role))
            ->when($branchId, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $branchId)
                ->orWhereHas('branches', fn ($b) => $b->where('branches.id', $branchId))))
            ->with(['roles', 'branch'])->orderBy('name')->paginate($request->perPage(20))->withQueryString();
        $periodValue = sprintf('%04d-%02d', $year, $month);
        $currentPeriod = now()->format('Y-m');

        return Inertia::render('Kpi/Monthly', [
            'staff' => $staff->through(function (User $s) use ($sheets, $evaluations, $month, $year, $periodValue) {
                $evaluation = $evaluations->get($s->id);
                $sheet = $sheets->sheet($s, $month, $year, $evaluation);
                $status = $evaluation?->status ?? KpiEvaluation::STATUS_PENDING;

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'branch' => $s->branch?->name,
                    'role' => AclHelper::roleLabel((string) $sheet['role']),
                    'rate_label' => $this->percent($sheet['total']).'%',
                    'amount_label' => $sheet['amount'] !== null ? Money::format($sheet['amount']) : '—',
                    'status' => $status,
                    'status_label' => KpiEvaluation::STATUS_LABELS[$status] ?? $status,
                    'status_color' => KpiEvaluation::STATUS_COLORS[$status] ?? 'neutral',
                    'url' => route('kpi.evaluate', ['userId' => $s->id, 'period' => $periodValue], false),
                ];
            }),
            'filters' => ['period' => $periodValue, 'role' => $role, 'branch_id' => $branchId],
            'currentPeriod' => $currentPeriod,
            'periodOptions' => Ui::options($this->periodOptions($periodValue)),
            'roleOptions' => Ui::options(collect(self::STAFF_ROLES)->mapWithKeys(fn ($r) => [$r => AclHelper::roleLabel($r)])),
            'branchOptions' => Ui::options($branches, 'name'),
        ]);
    }

    /** 12 kỳ lương gần nhất (+ kỳ đang xem): "Kỳ lương tháng MM/YYYY". */
    private function periodOptions(string $periodValue): \Illuminate\Support\Collection
    {
        $label = fn (string $ym) => 'Kỳ lương tháng '.substr($ym, 5, 2).'/'.substr($ym, 0, 4);

        return collect(range(0, 11))->map(fn ($i) => now()->startOfMonth()->subMonths($i)->format('Y-m'))
            ->push($periodValue)->unique()->sortDesc()->mapWithKeys(fn ($ym) => [$ym => $label($ym)]);
    }

    private function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }

    /** Tháng/năm từ ?period=YYYY-MM (ô chọn kỳ theo mockup) hoặc ?month=&year=. */
    private function monthYear(Request $request): array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', (string) $request->input('period'), $m)) {
            return [min(12, max(1, (int) $m[2])), (int) $m[1]];
        }

        return [min(12, max(1, (int) $request->input('month', now()->month))), (int) $request->input('year', now()->year)];
    }

    /** Kỳ lương của tháng đã duyệt / đã chi trả thì khóa toàn bộ dữ liệu lương, gồm phiếu KPI tháng đó. */
    private function periodLocked(int $month, int $year): bool
    {
        $monthStart = Carbon::create($year, $month, 1);

        return PayrollPeriod::isLockedFor($monthStart) || PayrollPeriod::isLockedFor($monthStart->copy()->endOfMonth());
    }

    /**
     * Phiếu KPI tháng của một nhân sự (mở trong modal từ danh sách): tiêu chí theo vai trò, số liệu hệ thống ghi nhận
     * hoặc ô điền tay, mức đạt 100 / 50 / 0%, tiền KPI (Học vụ), nút Duyệt / Không duyệt (có lý do).
     */
    public function evaluate(Request $request, int $userId, KpiSheetService $sheets): InertiaResponse
    {
        $this->guard();
        $staff = $this->scopedStaff(User::query())->with('branch')->findOrFail($userId);
        [$month, $year] = $this->monthYear($request);
        $evaluation = KpiEvaluation::with(['items', 'evaluator'])->where('user_id', $userId)->where('month', $month)->where('year', $year)->first();
        $sheet = $sheets->sheet($staff, $month, $year, $evaluation);
        $status = $evaluation?->status ?? KpiEvaluation::STATUS_PENDING;
        $isSelf = $userId === (int) $request->user()->id;
        $locked = $this->periodLocked($month, $year);
        $closeOn = Carbon::create($year, $month, 1)->endOfMonth()->startOfDay();
        $weightTotal = (float) $sheet['lines']->sum(fn ($l) => (float) $l['criterion']->weight);

        return $this->modalPage('Kpi/Evaluate', [
            'staff' => [
                'id' => $staff->id,
                'name' => $staff->name,
                'role_label' => AclHelper::roleLabel((string) $sheet['role']),
                'branch' => $staff->branch?->name,
            ],
            'month' => $month,
            'year' => $year,
            'periodLabel' => 'Kỳ lương tháng '.sprintf('%02d/%04d', $month, $year),
            'status' => $status,
            'statusLabel' => KpiEvaluation::STATUS_LABELS[$status] ?? $status,
            'statusColor' => KpiEvaluation::STATUS_COLORS[$status] ?? 'neutral',
            'rejectReason' => $status === KpiEvaluation::STATUS_REJECTED ? $evaluation?->reject_reason : null,
            'decidedBy' => $evaluation?->evaluator?->name,
            'decidedAt' => $evaluation?->decided_at?->format('H:i d/m/Y'),
            'fund' => $sheet['fund'],
            'weightTotal' => $weightTotal,
            'isSelf' => $isSelf,
            'locked' => $locked,
            // Chủ dự án chốt: KPI duyệt từ ngày cuối tháng; trước đó chỉ xem / điền dần.
            'canClose' => now()->gte($closeOn),
            'closeOn' => $closeOn->format('d/m/Y'),
            'canDecide' => $request->user()->can('kpi.confirm') && ! $isSelf && ! $locked && $status !== KpiEvaluation::STATUS_APPROVED,
            'groups' => $sheet['lines']->groupBy(fn ($l) => $l['criterion']->group_name ?: 'Chưa phân nhóm')
                ->map(fn ($lines, $name) => [
                    'name' => $name,
                    'items' => $lines->map(fn ($l) => [
                        'id' => $l['criterion']->id,
                        'name' => $l['criterion']->name,
                        'description' => $l['criterion']->description,
                        'weight' => (float) $l['criterion']->weight,
                        'weight_label' => $this->percent((float) $l['criterion']->weight),
                        'max_amount' => $l['max_amount'] !== null ? round($l['max_amount']) : null,
                        'threshold_full' => $l['criterion']->threshold_full ?: '—',
                        'threshold_half' => $l['criterion']->threshold_half ?: '—',
                        'max_full' => $l['criterion']->max_full,
                        'max_half' => $l['criterion']->max_half,
                        'count_based' => $l['criterion']->isCountBased(),
                        'auto' => $l['auto'],
                        'value' => $l['value'],
                        'level' => $l['level'],
                        'evidence' => $l['evidence'],
                    ])->values(),
                ])->values(),
            'backUrl' => route('kpi.monthly', ['period' => sprintf('%04d-%02d', $year, $month)]),
        ]);
    }

    public function evaluateStore(Request $request, int $userId, KpiSheetService $sheets)
    {
        $this->guard('kpi.confirm');
        $staff = $this->scopedStaff(User::query())->findOrFail($userId);
        // Nhân viên không tự chấm KPI của chính mình (A3 / Phase 3)
        abort_if($userId === (int) $request->user()->id, 403, 'Bạn không được tự chấm KPI của chính mình.');
        $validated = $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2100',
            'comment' => 'nullable|string',
            'score' => 'required_unless:action,approve,reject|array',
            'score.*' => 'nullable|numeric|min:0|max:100',
            'note' => 'nullable|array',
            'actual' => 'nullable|array',
            'actual.*' => 'nullable|string|max:255',
            'critical' => 'nullable|array',
            'strengths' => 'nullable|string|max:2000',
            'improvements' => 'nullable|string|max:2000',
            'next_actions' => 'nullable|string|max:2000',
            // Phiếu KPI tháng: approve = Duyệt (vào bảng lương), reject = Không duyệt (bắt buộc lý do).
            // draft / confirm: cách gửi điểm % cũ (lưu nháp / chốt), giữ cho tương thích.
            'action' => 'nullable|in:draft,confirm,approve,reject',
            'reject_reason' => 'required_if:action,reject|nullable|string|max:1000',
        ], ['reject_reason.required_if' => 'Cần ghi lý do không duyệt.']);
        $monthStart = Carbon::create((int) $validated['year'], (int) $validated['month'], 1);
        if ($this->periodLocked((int) $validated['month'], (int) $validated['year'])) {
            $message = 'Kỳ lương tháng '.$monthStart->format('m/Y').' đã duyệt — không thể sửa đánh giá KPI của tháng này.';

            return back()->withInput()->withErrors(['month' => $message])->with('error', $message);
        }

        $action = $validated['action'] ?? 'confirm';
        // Chủ dự án chốt: KPI chốt vào ngày cuối tháng — chưa tới ngày đó không duyệt / chốt được.
        $kpiCloseOn = $monthStart->copy()->endOfMonth()->startOfDay();
        if (in_array($action, ['confirm', 'approve'], true) && now()->lt($kpiCloseOn)) {
            $message = $action === 'approve'
                ? 'Duyệt KPI từ ngày cuối tháng '.$kpiCloseOn->format('d/m').'.'
                : 'Chốt KPI từ ngày cuối tháng '.$kpiCloseOn->format('d/m').' — hiện chỉ được lưu nháp.';

            return back()->withInput()->withErrors(['month' => $message])->with('error', $message);
        }

        if (in_array($action, ['approve', 'reject'], true)) {
            return $this->decideSheet($staff, $validated, $action, $sheets);
        }

        $critical = collect($validated['critical'] ?? [])->filter()->keys()->map(fn ($id) => (int) $id)->all();
        $kpiRole = KpiCriterion::roleFor($staff);
        $criteria = $kpiRole ? KpiCriterion::forRole($kpiRole)->active()->get()->keyBy('id') : collect();

        // Tiêu chí đếm lỗi: nhập SỐ LẦN thực tế, hệ thống tự ra mức 100 / 50 / 0% (không tự chọn %). Chưa nhập số → giữ % gửi lên.
        foreach ($criteria as $criterionId => $criterion) {
            $actual = $validated['actual'][$criterionId] ?? null;
            if ($criterion->isCountBased() && is_numeric($actual) && (float) $actual >= 0) {
                $validated['score'][$criterionId] = $criterion->levelForCount((float) $actual);
            }
        }
        // "Lỗi nghiêm trọng" đưa % đạt của mục về 0.
        foreach ($critical as $criterionId) {
            $validated['score'][$criterionId] = 0;
        }

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

        // Phiếu + từng mục ghi cùng lúc: lỗi giữa chừng không để lại phiếu đã chốt thiếu mục.
        $evaluation = DB::transaction(function () use ($userId, $validated, $total, $criteria, $critical, $action) {
            $evaluation = KpiEvaluation::updateOrCreate(
                ['user_id' => $userId, 'month' => $validated['month'], 'year' => $validated['year']],
                [
                    'evaluator_id' => Auth::id(),
                    'total_score' => $total,
                    'comment' => $validated['comment'] ?? null,
                    'strengths' => $validated['strengths'] ?? null,
                    'improvements' => $validated['improvements'] ?? null,
                    'next_actions' => $validated['next_actions'] ?? null,
                    'status' => $action === 'draft' ? KpiEvaluation::STATUS_PENDING : KpiEvaluation::STATUS_APPROVED,
                    'decided_at' => $action === 'draft' ? null : now(),
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

            return $evaluation;
        });

        $label = $evaluation->status === KpiEvaluation::STATUS_PENDING ? 'Đã lưu nháp đánh giá KPI' : 'Đã chốt KPI tháng';

        return redirect()->route('kpi.monthly', ['month' => $validated['month'], 'year' => $validated['year']])
            ->with('success', "{$label} (Tổng điểm: {$total}%).");
    }

    /**
     * Duyệt / Không duyệt phiếu KPI tháng. Lưu số các tiêu chí điền tay; tiêu chí tự động lấy số hệ thống đếm (kèm danh
     * sách bản ghi để đối chiếu về sau). Duyệt cần đủ số mọi tiêu chí; Không duyệt cần lý do, phiếu không vào bảng lương.
     */
    private function decideSheet(User $staff, array $validated, string $action, KpiSheetService $sheets)
    {
        [$month, $year] = [(int) $validated['month'], (int) $validated['year']];
        $kpiRole = KpiCriterion::roleFor($staff);
        $criteria = $kpiRole ? KpiCriterion::forRole($kpiRole)->active()->get() : collect();
        $manual = $criteria->reject(fn (KpiCriterion $c) => $c->isAuto())->filter(fn (KpiCriterion $c) => $c->isCountBased());

        $errors = [];
        $values = [];
        foreach ($manual as $criterion) {
            $raw = trim((string) ($validated['actual'][$criterion->id] ?? ''));
            if ($raw === '') {
                continue;
            }
            if (! ctype_digit($raw) || (int) $raw > 9999) {
                $errors["actual.{$criterion->id}"] = "Số liệu \"{$criterion->name}\" phải là số nguyên từ 0.";

                continue;
            }
            $values[$criterion->id] = (int) $raw;
        }
        if ($errors) {
            return back()->withErrors($errors);
        }

        $evaluation = DB::transaction(function () use ($staff, $month, $year, $values, $action, $validated, $sheets, &$errors) {
            $evaluation = KpiEvaluation::firstOrCreate(
                ['user_id' => $staff->id, 'month' => $month, 'year' => $year],
                ['total_score' => 0, 'status' => KpiEvaluation::STATUS_PENDING]
            );
            if ($evaluation->status === KpiEvaluation::STATUS_APPROVED) {
                $errors['month'] = 'Phiếu KPI này đã duyệt.';

                return null;
            }
            foreach ($values as $criterionId => $value) {
                KpiEvaluationItem::updateOrCreate(
                    ['kpi_evaluation_id' => $evaluation->id, 'kpi_criterion_id' => $criterionId],
                    ['actual' => (string) $value]
                );
            }

            $sheet = $sheets->sheet($staff, $month, $year, $evaluation->fresh('items'));
            if ($action === 'approve' && $sheet['missing'] > 0) {
                $errors['actual'] = "Còn {$sheet['missing']} tiêu chí chưa có số liệu.";

                return null;
            }
            foreach ($sheet['lines'] as $line) {
                if ($line['value'] === null && ! $line['criterion']->isCountBased()) {
                    continue;   // tiêu chí % cũ chưa chấm: giữ nguyên
                }
                KpiEvaluationItem::updateOrCreate(
                    ['kpi_evaluation_id' => $evaluation->id, 'kpi_criterion_id' => $line['criterion']->id],
                    [
                        'actual' => $line['value'] === null ? null : (string) $line['value'],
                        'score' => $line['level'] ?? 0,
                        'evidence' => $line['auto'] ? $line['evidence'] : null,
                    ]
                );
            }
            $evaluation->update([
                'evaluator_id' => Auth::id(),
                'total_score' => $sheet['total'],
                'status' => $action === 'approve' ? KpiEvaluation::STATUS_APPROVED : KpiEvaluation::STATUS_REJECTED,
                'reject_reason' => $action === 'reject' ? trim((string) $validated['reject_reason']) : null,
                'decided_at' => now(),
            ]);

            return $evaluation;
        });

        if (! $evaluation) {
            return back()->withErrors($errors);
        }

        $message = $action === 'approve'
            ? "Đã duyệt KPI tháng {$month}/{$year} của {$staff->name} ({$this->percent((float) $evaluation->total_score)}%)."
            : "Đã không duyệt phiếu KPI của {$staff->name}.";

        return $this->modalSaved($message, route('kpi.monthly', ['period' => sprintf('%04d-%02d', $year, $month)]), 'success');
    }

    /** KPI của tôi: phiếu KPI tháng của chính người đang đăng nhập (số liệu từng tiêu chí, mức đạt, tiền KPI, trạng thái). */
    public function mine(Request $request, KpiSheetService $sheets): InertiaResponse
    {
        $user = $request->user();
        [$month, $year] = $this->monthYear($request);
        $evaluation = KpiEvaluation::with(['items', 'evaluator'])->where('user_id', $user->id)->where('month', $month)->where('year', $year)->first();
        $sheet = $sheets->sheet($user, $month, $year, $evaluation);
        $status = $evaluation?->status ?? KpiEvaluation::STATUS_PENDING;
        $periodValue = sprintf('%04d-%02d', $year, $month);

        return Inertia::render('Kpi/Mine', [
            'hasKpi' => $sheet['role'] !== null,
            'name' => $user->name,
            'roleLabel' => AclHelper::roleLabel((string) $sheet['role']),
            'period' => $periodValue,
            'periodOptions' => Ui::options($this->periodOptions($periodValue)),
            'status' => $status,
            'statusLabel' => KpiEvaluation::STATUS_LABELS[$status] ?? $status,
            'statusColor' => KpiEvaluation::STATUS_COLORS[$status] ?? 'neutral',
            'rejectReason' => $status === KpiEvaluation::STATUS_REJECTED ? $evaluation?->reject_reason : null,
            'decidedBy' => $evaluation?->evaluator?->name,
            'decidedAt' => $evaluation?->decided_at?->format('d/m/Y'),
            'fund' => $sheet['fund'],
            'amount' => $sheet['amount'],
            'rateLabel' => $this->percent($sheet['total']).'%',
            'groups' => $sheet['lines']->groupBy(fn ($l) => $l['criterion']->group_name ?: 'Chưa phân nhóm')
                ->map(fn ($lines, $name) => [
                    'name' => $name,
                    'items' => $lines->map(fn ($l) => [
                        'id' => $l['criterion']->id,
                        'name' => $l['criterion']->name,
                        'unit' => KpiCriterion::unitLabel($l['criterion']->unit),
                        'threshold_full' => $l['criterion']->threshold_full ?: '—',
                        'threshold_half' => $l['criterion']->threshold_half ?: '—',
                        'max_half' => $l['criterion']->max_half,
                        'value' => $l['value'],
                        'level' => $l['level'],
                        'amount' => $l['amount'] !== null ? round($l['amount']) : null,
                        'evidence' => $l['evidence'],
                    ])->values(),
                ])->values(),
        ]);
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
