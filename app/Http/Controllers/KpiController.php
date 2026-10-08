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
use Illuminate\Support\Collection;
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
            'measureOptions' => Ui::options(KpiCriterion::MEASURES),
            'periodMonths' => KpiCriterion::periodMonths($role),
            'cycleOptions' => Ui::options(KpiCriterion::CYCLES),
            'grades' => KpiCriterion::hasGrades($role, KpiCriterion::periodMonths($role)) ? array_map(fn ($g) => ['grade' => $g[0], 'from' => $g[1], 'label' => $g[2], 'pay' => $g[3]], KpiCriterion::GRADES) : [],
            'gradeFund' => KpiCriterion::gradeFund($role),
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
                        'measure' => in_array($cr->measure, array_keys(KpiCriterion::MEASURES), true) ? $cr->measure : 'count',
                        'tiers' => $cr->tierList(),
                        'linear' => (bool) $cr->linear,
                        'full_at' => $cr->full_at !== null ? (float) $cr->full_at : null,
                        'per_month' => (bool) $cr->per_month,
                        'knockout' => (bool) $cr->knockout,
                        'allow_na' => (bool) $cr->allow_na,
                        'rule_label' => $cr->ruleLabel(),
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
     * Tiêu chí KPI (dữ liệu chuẩn hóa để tính KPI): nhóm, tên, trọng số, cách đo (đếm số lần / tỉ lệ %), bậc tính điểm
     * ([ngưỡng, % điểm]) hoặc tính thẳng theo tỉ lệ, nguồn số liệu.
     */
    private function criterionRules(): array
    {
        return [
            'group_name' => 'nullable|string|max:255',
            'new_group' => 'nullable|string|max:255',
            'name' => 'required|string|max:255',
            'weight' => 'required|numeric|min:0|max:100',
            'measure' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(KpiCriterion::MEASURES))],
            'unit' => [\Illuminate\Validation\Rule::requiredIf(fn () => request()->input('measure', 'count') === 'count'), 'nullable', \Illuminate\Validation\Rule::in(array_keys(KpiCriterion::UNITS))],
            'tiers' => 'nullable|array|max:8',
            // Cách gửi cũ (bộ Học vụ): 2 ngưỡng số lần cho mức 100% / 50% thay cho bậc tính điểm.
            'max_full' => [\Illuminate\Validation\Rule::requiredIf(fn () => $this->needsLegacyThresholds()), 'nullable', 'integer', 'min:0', 'max:9999'],
            'max_half' => [\Illuminate\Validation\Rule::requiredIf(fn () => $this->needsLegacyThresholds()), 'nullable', 'integer', 'min:0', 'max:9999', 'gte:max_full'],
            'tiers.*.at' => 'nullable|numeric|min:0|max:9999',
            'tiers.*.percent' => 'nullable|numeric|min:0|max:100',
            'linear' => 'nullable|boolean',
            'full_at' => 'nullable|numeric|min:0.01|max:1000',
            'per_month' => 'nullable|boolean',
            'knockout' => 'nullable|boolean',
            'allow_na' => 'nullable|boolean',
            'description' => 'nullable|string|max:2000',
            'auto_source' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(KpiCriterion::AUTO_SOURCES))],
        ];
    }

    /** Tiêu chí đếm số lần gửi không kèm bậc tính điểm → cần 2 ngưỡng max_full / max_half. */
    private function needsLegacyThresholds(): bool
    {
        $request = request();

        return ! $request->has('tiers') && $request->input('measure', 'count') === 'count' && ! $request->boolean('knockout');
    }

    /**
     * Nhóm mới (nếu gõ) thay nhóm chọn; bậc tính điểm chuẩn hóa (bỏ dòng trống, sắp theo ngưỡng). Đếm số lần đúng 2 bậc
     * 100% / 50% lưu ở max_full / max_half (cách của bộ Học vụ, nhãn "Ngưỡng 100 / 50" tự sinh); còn lại lưu bậc riêng.
     */
    private function normalizeCriterion(array $validated): array
    {
        $newGroup = trim((string) ($validated['new_group'] ?? ''));
        if ($newGroup !== '') {
            $validated['group_name'] = $newGroup;
        }
        unset($validated['new_group']);
        $validated['auto_source'] = ($validated['auto_source'] ?? null) ?: null;
        $validated['measure'] = in_array($validated['measure'] ?? null, array_keys(KpiCriterion::MEASURES), true) ? $validated['measure'] : 'count';
        $rate = $validated['measure'] !== 'count';
        $lowerBetter = $validated['measure'] !== 'rate';
        $validated['knockout'] = ! $rate && (bool) ($validated['knockout'] ?? false);
        $validated['linear'] = $validated['measure'] === 'rate' && (bool) ($validated['linear'] ?? false);
        $validated['allow_na'] = (bool) ($validated['allow_na'] ?? false);
        $validated['per_month'] = (bool) ($validated['per_month'] ?? false);
        $validated['full_at'] = $validated['linear'] && is_numeric($validated['full_at'] ?? null) ? (float) $validated['full_at'] : null;
        if ($rate) {
            $validated['unit'] = KpiCriterion::RATE_UNIT;
        }

        $rows = $validated['tiers'] ?? (isset($validated['max_full'], $validated['max_half'])
            ? [['at' => $validated['max_full'], 'percent' => 100], ['at' => $validated['max_half'], 'percent' => 50]] : []);
        $tiers = collect($rows)
            ->filter(fn ($t) => is_numeric($t['at'] ?? null) && is_numeric($t['percent'] ?? null))
            ->map(fn ($t) => [(float) $t['at'], (float) $t['percent']])
            ->unique(0);
        $tiers = ($lowerBetter ? $tiers->sortBy(0) : $tiers->sortByDesc(0))->values();
        if ($validated['knockout']) {
            $tiers = collect([[0.0, 100.0]]);   // có từ 1 lần là mất toàn bộ KPI kỳ; mức đạt của chính mục không ảnh hưởng
        }
        if (! $validated['linear'] && $tiers->isEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['tiers' => 'Cần ít nhất một bậc tính điểm (ngưỡng và % điểm).']);
        }

        $twoLevel = ! $rate && ! $validated['knockout'] && $tiers->count() === 2 && $tiers->pluck(1)->all() === [100.0, 50.0];
        $validated['max_full'] = $twoLevel ? (int) $tiers[0][0] : null;
        $validated['max_half'] = $twoLevel ? (int) $tiers[1][0] : null;
        $validated['tiers'] = $twoLevel || $validated['linear'] ? null : $tiers->all();
        $validated['threshold_full'] = $twoLevel ? KpiCriterion::thresholdLabel($validated['max_full'], $validated['unit']) : null;
        $validated['threshold_half'] = $twoLevel ? KpiCriterion::thresholdLabel($validated['max_half'], $validated['unit']) : null;

        return $validated;
    }

    /** Chu kỳ chấm KPI của vai trò: theo tháng / theo quý (phiếu quý lưu ở tháng đầu quý, duyệt từ ngày cuối quý). */
    public function criteriaCycle(Request $request)
    {
        $this->guard('kpi.manage');
        $validated = $request->validate([
            'role' => ['required', \Illuminate\Validation\Rule::in(KpiCriterion::ROLES)],
            'period_months' => ['required', \Illuminate\Validation\Rule::in(array_keys(KpiCriterion::CYCLES))],
            'grade_fund' => 'nullable|numeric|min:0|max:1000000000',
        ]);
        \App\Models\SystemSetting::set('kpi_cycle_'.$validated['role'], (string) $validated['period_months'], 'Chu kỳ chấm KPI (1 = tháng, 3 = quý)');
        if ($request->has('grade_fund')) {
            // Thưởng KPI tối đa / tháng theo xếp loại; để trống = vai trò không có khoản thưởng này.
            \App\Models\SystemSetting::set('kpi_grade_fund_'.$validated['role'], (string) (int) ($validated['grade_fund'] ?? 0), 'Thưởng KPI tối đa / tháng theo xếp loại (đ)');
        }

        return redirect()->route('kpi.criteria', ['role' => $validated['role']])
            ->with('success', 'Đã đổi chu kỳ chấm KPI: '.mb_strtolower(KpiCriterion::CYCLES[(int) $validated['period_months']]).'.');
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

        // Phiếu tháng lưu ở tháng đó, phiếu quý ở tháng đầu quý: nạp cả hai rồi chọn theo kỳ của từng người.
        $quarterStart = intdiv($month - 1, 3) * 3 + 1;
        $evaluations = KpiEvaluation::with('items')->where('year', $year)->whereIn('month', array_unique([$month, $quarterStart]))->get()
            ->groupBy(fn (KpiEvaluation $e) => $e->user_id.'-'.$e->track);
        $staff = $this->scopedStaff($sheets->staffQuery($role))
            ->when($branchId, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $branchId)
                ->orWhereHas('branches', fn ($b) => $b->where('branches.id', $branchId))))
            ->with(['roles', 'branch'])->orderBy('name')->paginate($request->perPage(20))->withQueryString();
        $periodValue = sprintf('%04d-%02d', $year, $month);
        $currentPeriod = now()->format('Y-m');

        return Inertia::render('Kpi/Monthly', [
            'staff' => $staff->through(function (User $s) use ($sheets, $evaluations, $month, $year, $periodValue, $role) {
                // Học thuật kiêm nhiệm giảng dạy: thêm phiếu KPI giảng dạy (bộ GV part-time). Lọc GV part-time chỉ hiện phiếu giảng dạy.
                $tracks = collect(KpiSheetService::tracksFor($s))
                    ->reject(fn (string $track) => $role !== null && KpiSheetService::roleFor($s, $track) !== $role)->values();
                $sheetRows = $tracks->map(function (string $track) use ($s, $sheets, $evaluations, $month, $year, $periodValue) {
                    $period = KpiSheetService::periodFor($s, $month, $year, $track);
                    $evaluation = $evaluations->get($s->id.'-'.$track, collect())->firstWhere('month', $period['month']);
                    $sheet = $sheets->sheet($s, $month, $year, $evaluation, $track);
                    $status = $evaluation?->status ?? KpiEvaluation::STATUS_PENDING;

                    return [
                        'track' => $track,
                        'role' => $this->sheetRoleLabel($sheet),
                        'period_label' => $period['months'] > 1 ? 'Phiếu '.mb_strtolower($period['label']) : null,
                        'rate_label' => $this->percent($sheet['total']).'%',
                        'amount_label' => $sheet['amount'] !== null ? Money::format($sheet['amount'])
                            : ($sheet['grade'] ? 'Loại '.$sheet['grade']['grade'].' · '.($sheet['bonus'] !== null ? Money::format($sheet['bonus']) : 'hệ số '.$sheet['grade']['pay'].'%') : '—'),
                        'status' => $status,
                        'status_label' => KpiEvaluation::STATUS_LABELS[$status] ?? $status,
                        'status_color' => KpiEvaluation::STATUS_COLORS[$status] ?? 'neutral',
                        'url' => route('kpi.evaluate', array_filter(['userId' => $s->id, 'period' => $periodValue, 'track' => $track === KpiEvaluation::TRACK_MAIN ? null : $track]), false),
                    ];
                })->values();

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'branch' => $s->branch?->name,
                    'sheets' => $sheetRows,
                ] + ($sheetRows->first() ?? []);
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

    /** Nhãn bộ tiêu chí của phiếu: vai trò, hoặc "KPI giảng dạy (kiêm nhiệm)" với phiếu giảng dạy của Học thuật. */
    private function sheetRoleLabel(array $sheet): string
    {
        return ($sheet['track'] ?? KpiEvaluation::TRACK_MAIN) === KpiEvaluation::TRACK_TEACHING
            ? 'KPI giảng dạy (kiêm nhiệm)'
            : AclHelper::roleLabel((string) $sheet['role']);
    }

    /** Mảng việc của phiếu từ ?track= (teaching chỉ với Học thuật kiêm nhiệm giảng dạy). */
    private function track(Request $request, User $staff): string
    {
        $track = (string) $request->input('track', KpiEvaluation::TRACK_MAIN);
        if ($track === KpiEvaluation::TRACK_MAIN || $track === '') {
            return KpiEvaluation::TRACK_MAIN;
        }
        abort_unless($track === KpiEvaluation::TRACK_TEACHING && KpiSheetService::hasTeachingSheet($staff), 404);

        return $track;
    }

    /** Tháng/năm từ ?period=YYYY-MM (ô chọn kỳ theo mockup) hoặc ?month=&year=. */
    private function monthYear(Request $request): array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', (string) $request->input('period'), $m)) {
            return [min(12, max(1, (int) $m[2])), (int) $m[1]];
        }

        return [min(12, max(1, (int) $request->input('month', now()->month))), (int) $request->input('year', now()->year)];
    }

    /** Kỳ lương của tháng đã duyệt / đã chi trả thì khóa toàn bộ dữ liệu lương, gồm phiếu KPI tháng đó (phiếu quý: tháng cuối quý). */
    private function periodLocked(int $month, int $year, int $months = 1): bool
    {
        $monthStart = Carbon::create($year, $month, 1)->addMonths(max(1, $months) - 1);

        return PayrollPeriod::isLockedFor($monthStart) || PayrollPeriod::isLockedFor($monthStart->copy()->endOfMonth());
    }

    /** Nhãn kỳ phiếu: "Kỳ lương tháng 10/2026" hoặc "Quý 4/2026 (tháng 10–12)". */
    private function periodLabel(array $period): string
    {
        return $period['months'] > 1
            ? $period['label'].' (tháng '.$period['from']->month.'–'.$period['to']->month.')'
            : 'Kỳ lương tháng '.sprintf('%02d/%04d', $period['month'], $period['year']);
    }

    /**
     * Dòng tiêu chí gửi xuống phiếu: số liệu, mức đạt, quy tắc tính (để tính ngay khi điền), bằng chứng.
     *
     * @return array<string, mixed>
     */
    private function lineProps(array $l): array
    {
        $c = $l['criterion'];

        return [
            'id' => $c->id,
            'name' => $c->name,
            'description' => $c->description,
            'weight' => (float) $c->weight,
            'weight_label' => $this->percent((float) $c->weight),
            'max_amount' => $l['max_amount'] !== null ? round($l['max_amount']) : null,
            'amount' => $l['amount'] !== null ? round($l['amount']) : null,
            'threshold_full' => $c->threshold_full ?: '—',
            'threshold_half' => $c->threshold_half ?: '—',
            'max_full' => $c->max_full,
            'max_half' => $c->max_half,
            'rule_label' => $c->ruleLabel() ?? '—',
            'rule' => $c->ruleForUi(),
            'measure' => $c->isRate() ? 'rate' : 'count',
            'unit' => KpiCriterion::unitLabel($c->unit),
            'per_month' => (bool) $c->per_month,
            'knockout' => (bool) $c->knockout,
            'count_based' => $c->hasRule(),
            'auto' => $l['auto'],
            'value' => $l['value'],
            'level' => $l['level'],
            'allow_na' => (bool) $c->allow_na && ! $l['auto'],
            'na' => $l['na'],
            'cap' => $l['cap'],
            'evidence' => $l['evidence'],
        ];
    }

    /** Phiếu chỉ gồm tiêu chí đếm 2 mức 100 / 50% (bộ Học vụ) → giữ hai cột "Đạt 100% khi" / "Đạt 50% khi". */
    private function simpleRules(Collection $lines): bool
    {
        return $lines->every(fn ($l) => ! $l['criterion']->hasRule()
            || ($l['criterion']->isCountBased() && ! $l['criterion']->knockout && collect($l['criterion']->tierList())->pluck(1)->values()->all() === [100.0, 50.0]));
    }

    /** Lựa chọn kỳ của trang KPI của tôi: 12 tháng gần nhất, hoặc 4 quý gần nhất với vai trò chấm theo quý. */
    private function ownPeriodOptions(?string $role, string $periodValue): \Illuminate\Support\Collection
    {
        if (KpiCriterion::periodMonths($role) === 1) {
            return $this->periodOptions($periodValue);
        }

        return collect(range(0, 3))->map(fn ($i) => now()->startOfMonth()->firstOfQuarter()->subMonths($i * 3))
            ->push(Carbon::parse($periodValue.'-01'))
            ->mapWithKeys(fn (Carbon $d) => [$d->format('Y-m') => KpiSheetService::period($role, $d->month, $d->year)['label']])
            ->sortKeysDesc();
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
        $track = $this->track($request, $staff);
        $evaluation = KpiSheetService::evaluationFor($staff, $month, $year, $track);
        $sheet = $sheets->sheet($staff, $month, $year, $evaluation, $track);
        $period = $sheet['period'];
        $status = $evaluation?->status ?? KpiEvaluation::STATUS_PENDING;
        $isSelf = $userId === (int) $request->user()->id;
        $locked = $this->periodLocked($period['month'], $period['year'], $period['months']);
        $closeOn = $period['to']->copy()->startOfDay();
        $weightTotal = (float) $sheet['lines']->sum(fn ($l) => (float) $l['criterion']->weight);

        return $this->modalPage('Kpi/Evaluate', [
            'staff' => [
                'id' => $staff->id,
                'name' => $staff->name,
                'role_label' => $this->sheetRoleLabel($sheet),
                'branch' => $staff->branch?->name,
            ],
            'track' => $track,
            'month' => $period['month'],
            'year' => $period['year'],
            'periodMonths' => $period['months'],
            'periodLabel' => $this->periodLabel($period),
            'status' => $status,
            'statusLabel' => KpiEvaluation::STATUS_LABELS[$status] ?? $status,
            'statusColor' => KpiEvaluation::STATUS_COLORS[$status] ?? 'neutral',
            'rejectReason' => $status === KpiEvaluation::STATUS_REJECTED ? $evaluation?->reject_reason : null,
            'decidedBy' => $evaluation?->evaluator?->name,
            'decidedAt' => $evaluation?->decided_at?->format('H:i d/m/Y'),
            'fund' => $sheet['fund'],
            'weightTotal' => $weightTotal,
            'grades' => KpiCriterion::hasGrades($sheet['role'], $period['months']) ? array_map(fn ($g) => ['grade' => $g[0], 'from' => $g[1], 'label' => $g[2], 'pay' => $g[3]], KpiCriterion::GRADES) : [],
            'simpleRules' => $this->simpleRules($sheet['lines']),
            'bonusFund' => $sheet['bonus_fund'] ?? KpiCriterion::gradeFund($sheet['role']),
            'gradeCap' => $sheet['lines']->contains(fn ($l) => $l['cap']) ? KpiSheetService::CAP_GRADE : null,
            'isSelf' => $isSelf,
            'locked' => $locked,
            // Chủ dự án chốt: KPI duyệt từ ngày cuối kỳ (cuối tháng, phiếu quý: cuối quý); trước đó chỉ xem / điền dần.
            'canClose' => now()->gte($closeOn),
            'closeOn' => $closeOn->format('d/m/Y'),
            'canDecide' => $request->user()->can('kpi.confirm') && ! $isSelf && ! $locked && $status !== KpiEvaluation::STATUS_APPROVED,
            'groups' => $sheet['lines']->groupBy(fn ($l) => $l['criterion']->group_name ?: 'Chưa phân nhóm')
                ->map(fn ($lines, $name) => [
                    'name' => $name,
                    'items' => $lines->map(fn ($l) => $this->lineProps($l))->values(),
                ])->values(),
            'backUrl' => route('kpi.monthly', ['period' => sprintf('%04d-%02d', $year, $month)]),
        ]);
    }

    public function evaluateStore(Request $request, int $userId, KpiSheetService $sheets)
    {
        $this->guard('kpi.confirm');
        $staff = $this->scopedStaff(User::query())->findOrFail($userId);
        $track = $this->track($request, $staff);
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
            'na' => 'nullable|array',
            'strengths' => 'nullable|string|max:2000',
            'improvements' => 'nullable|string|max:2000',
            'next_actions' => 'nullable|string|max:2000',
            // Phiếu KPI tháng: approve = Duyệt (vào bảng lương), reject = Không duyệt (bắt buộc lý do).
            // draft / confirm: cách gửi điểm % cũ (lưu nháp / chốt), giữ cho tương thích.
            'action' => 'nullable|in:draft,confirm,approve,reject',
            'reject_reason' => 'required_if:action,reject|nullable|string|max:1000',
        ], ['reject_reason.required_if' => 'Cần ghi lý do không duyệt.']);
        $action = $validated['action'] ?? 'confirm';
        // Phiếu Duyệt / Không duyệt theo kỳ của vai trò (tháng / quý); cách gửi điểm cũ luôn theo tháng.
        $period = in_array($action, ['approve', 'reject'], true)
            ? KpiSheetService::periodFor($staff, (int) $validated['month'], (int) $validated['year'], $track)
            : KpiSheetService::period(null, (int) $validated['month'], (int) $validated['year']);
        $validated['month'] = $period['month'];
        $monthStart = Carbon::create($period['year'], $period['month'], 1);
        if ($this->periodLocked($period['month'], $period['year'], $period['months'])) {
            $message = $period['months'] > 1
                ? 'Kỳ lương tháng cuối '.mb_strtolower($period['label']).' đã duyệt — không thể sửa đánh giá KPI của quý này.'
                : 'Kỳ lương tháng '.$monthStart->format('m/Y').' đã duyệt — không thể sửa đánh giá KPI của tháng này.';

            return back()->withInput()->withErrors(['month' => $message])->with('error', $message);
        }

        // Chủ dự án chốt: KPI chốt vào ngày cuối kỳ (cuối tháng; phiếu quý: cuối quý) — chưa tới ngày đó không duyệt / chốt được.
        $kpiCloseOn = $period['to']->copy()->startOfDay();
        if (in_array($action, ['confirm', 'approve'], true) && now()->lt($kpiCloseOn)) {
            $message = match (true) {
                $period['months'] > 1 => 'Duyệt KPI quý từ ngày cuối quý '.$kpiCloseOn->format('d/m').'.',
                $action === 'approve' => 'Duyệt KPI từ ngày cuối tháng '.$kpiCloseOn->format('d/m').'.',
                default => 'Chốt KPI từ ngày cuối tháng '.$kpiCloseOn->format('d/m').' — hiện chỉ được lưu nháp.',
            };

            return back()->withInput()->withErrors(['month' => $message])->with('error', $message);
        }

        if (in_array($action, ['approve', 'reject'], true)) {
            return $this->decideSheet($staff, $validated, $action, $sheets, $track);
        }

        $critical = collect($validated['critical'] ?? [])->filter()->keys()->map(fn ($id) => (int) $id)->all();
        $kpiRole = KpiSheetService::roleFor($staff, $track);
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
        $evaluation = DB::transaction(function () use ($userId, $validated, $total, $criteria, $critical, $action, $track) {
            $evaluation = KpiEvaluation::updateOrCreate(
                ['user_id' => $userId, 'month' => $validated['month'], 'year' => $validated['year'], 'track' => $track],
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
    private function decideSheet(User $staff, array $validated, string $action, KpiSheetService $sheets, string $track = KpiEvaluation::TRACK_MAIN)
    {
        $period = KpiSheetService::periodFor($staff, (int) $validated['month'], (int) $validated['year'], $track);
        [$month, $year] = [$period['month'], $period['year']];
        $kpiRole = KpiSheetService::roleFor($staff, $track);
        $criteria = $kpiRole ? KpiCriterion::forRole($kpiRole)->active()->get() : collect();
        // Số điền tay: tiêu chí không tự động, và nguồn tỉ lệ tự động (kỳ chưa có dữ liệu thì điền tay).
        $manual = $criteria->filter(fn (KpiCriterion $c) => $c->hasRule() && (! $c->isAuto() || $c->isRateSource()));

        $errors = [];
        $values = [];
        // Không phát sinh (tiêu chí cho phép): 1 = đánh dấu, 0 = bỏ đánh dấu.
        $na = [];
        foreach ($manual->filter(fn (KpiCriterion $c) => $c->allow_na) as $criterion) {
            if (array_key_exists($criterion->id, $validated['na'] ?? [])) {
                $na[$criterion->id] = (bool) $validated['na'][$criterion->id];
            }
        }
        foreach ($manual as $criterion) {
            if ($na[$criterion->id] ?? false) {
                continue;
            }
            $raw = str_replace(',', '.', trim((string) ($validated['actual'][$criterion->id] ?? '')));
            if ($raw === '') {
                continue;
            }
            if ($criterion->isRate()) {
                if (! is_numeric($raw) || (float) $raw < 0 || (float) $raw > 1000) {
                    $errors["actual.{$criterion->id}"] = "Số liệu \"{$criterion->name}\" phải là tỉ lệ % từ 0.";

                    continue;
                }
                $values[$criterion->id] = round((float) $raw, 2);

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

        $evaluation = DB::transaction(function () use ($staff, $month, $year, $period, $values, $na, $action, $validated, $sheets, $track, &$errors) {
            $evaluation = KpiEvaluation::firstOrCreate(
                ['user_id' => $staff->id, 'month' => $month, 'year' => $year, 'track' => $track],
                ['period_months' => $period['months'], 'total_score' => 0, 'status' => KpiEvaluation::STATUS_PENDING]
            );
            if ($evaluation->status === KpiEvaluation::STATUS_APPROVED) {
                $errors['month'] = 'Phiếu KPI này đã duyệt.';

                return null;
            }
            foreach ($na as $criterionId => $notApplicable) {
                KpiEvaluationItem::updateOrCreate(
                    ['kpi_evaluation_id' => $evaluation->id, 'kpi_criterion_id' => $criterionId],
                    $notApplicable ? ['actual' => null, 'not_applicable' => true] : ['not_applicable' => false]
                );
            }
            foreach ($values as $criterionId => $value) {
                KpiEvaluationItem::updateOrCreate(
                    ['kpi_evaluation_id' => $evaluation->id, 'kpi_criterion_id' => $criterionId],
                    ['actual' => (string) $value, 'not_applicable' => false]
                );
            }

            $sheet = $sheets->sheet($staff, $month, $year, $evaluation->fresh('items'), $track);
            if ($action === 'approve' && $sheet['missing'] > 0) {
                $errors['actual'] = "Còn {$sheet['missing']} tiêu chí chưa có số liệu.";

                return null;
            }
            foreach ($sheet['lines'] as $line) {
                if ($line['value'] === null && ! $line['criterion']->hasRule()) {
                    continue;   // tiêu chí % cũ chưa chấm: giữ nguyên
                }
                KpiEvaluationItem::updateOrCreate(
                    ['kpi_evaluation_id' => $evaluation->id, 'kpi_criterion_id' => $line['criterion']->id],
                    [
                        'actual' => $line['value'] === null ? null : (string) $line['value'],
                        'score' => $line['level'] ?? 0,
                        'evidence' => $line['auto'] ? $line['evidence'] : null,
                        'not_applicable' => $line['na'],
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

        $what = ($track === KpiEvaluation::TRACK_TEACHING ? 'giảng dạy ' : '').($period['months'] > 1 ? mb_strtolower($period['label']) : "tháng {$month}/{$year}");
        $message = $action === 'approve'
            ? "Đã duyệt KPI {$what} của {$staff->name} ({$this->percent((float) $evaluation->total_score)}%)."
            : "Đã không duyệt phiếu KPI của {$staff->name}.";

        return $this->modalSaved($message, route('kpi.monthly', ['period' => sprintf('%04d-%02d', $year, $month)]), 'success');
    }

    /** KPI của tôi: phiếu KPI tháng của chính người đang đăng nhập (số liệu từng tiêu chí, mức đạt, tiền KPI, trạng thái). */
    public function mine(Request $request, KpiSheetService $sheets): InertiaResponse
    {
        $user = $request->user();
        [$month, $year] = $this->monthYear($request);
        $track = $this->track($request, $user);
        $evaluation = KpiSheetService::evaluationFor($user, $month, $year, $track);
        $sheet = $sheets->sheet($user, $month, $year, $evaluation, $track);
        $period = $sheet['period'];
        $status = $evaluation?->status ?? KpiEvaluation::STATUS_PENDING;
        $periodValue = $period['key'];

        return Inertia::render('Kpi/Mine', [
            'hasKpi' => $sheet['role'] !== null,
            'name' => $user->name,
            'roleLabel' => $this->sheetRoleLabel($sheet),
            // Học thuật kiêm nhiệm giảng dạy: chuyển giữa phiếu Học thuật và phiếu KPI giảng dạy.
            'track' => $track,
            'trackOptions' => KpiSheetService::hasTeachingSheet($user) ? [
                ['value' => KpiEvaluation::TRACK_MAIN, 'label' => AclHelper::roleLabel((string) KpiCriterion::roleFor($user))],
                ['value' => KpiEvaluation::TRACK_TEACHING, 'label' => 'KPI giảng dạy (kiêm nhiệm)'],
            ] : [],
            'period' => $periodValue,
            'periodMonths' => $period['months'],
            'periodLabel' => $this->periodLabel($period),
            'periodOptions' => Ui::options($this->ownPeriodOptions($sheet['role'], $periodValue)),
            'status' => $status,
            'statusLabel' => KpiEvaluation::STATUS_LABELS[$status] ?? $status,
            'statusColor' => KpiEvaluation::STATUS_COLORS[$status] ?? 'neutral',
            'rejectReason' => $status === KpiEvaluation::STATUS_REJECTED ? $evaluation?->reject_reason : null,
            'decidedBy' => $evaluation?->evaluator?->name,
            'decidedAt' => $evaluation?->decided_at?->format('d/m/Y'),
            'fund' => $sheet['fund'],
            'amount' => $sheet['amount'],
            'rateLabel' => $this->percent($sheet['total']).'%',
            'grade' => $sheet['grade'],
            'bonus' => $sheet['bonus'],
            'bonusFund' => $sheet['bonus_fund'],
            'missing' => $status === KpiEvaluation::STATUS_APPROVED ? 0 : $sheet['missing'],
            'knockout' => $sheet['knockout'],
            'groups' => $sheet['lines']->groupBy(fn ($l) => $l['criterion']->group_name ?: 'Chưa phân nhóm')
                ->map(fn ($lines, $name) => [
                    'name' => $name,
                    'items' => $lines->map(fn ($l) => $this->lineProps($l))->values(),
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
