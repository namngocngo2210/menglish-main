<?php

namespace App\Services\Kpi;

use App\Models\KpiCriterion;
use App\Models\KpiEvaluation;
use App\Models\User;
use App\Support\Roles;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Phiếu KPI: mỗi nhân sự có vai trò được chấm KPI (và vai trò đó có tiêu chí đang áp dụng) một phiếu mỗi kỳ — kỳ là tháng,
 * hoặc quý với vai trò chấm theo quý (KpiCriterion::periodMonths, mặc định GV part-time). Phiếu quý lưu ở tháng đầu quý.
 * Tiêu chí có nguồn tự động lấy số từ KpiAutoCounter; tiêu chí điền tay (và nguồn tỉ lệ chưa có dữ liệu) lấy số người chấm
 * đã điền (kpi_evaluation_items.actual). Phiếu đã duyệt dùng số và mức đạt đã chốt lúc duyệt, không đếm lại.
 */
class KpiSheetService
{
    public function __construct(private KpiAutoCounter $counter) {}

    /** @return array{0: Carbon, 1: Carbon} đầu tháng đầu kỳ và cuối tháng cuối kỳ */
    public static function range(int $month, int $year, int $months = 1): array
    {
        $from = Carbon::create($year, $month, 1)->startOfDay();

        return [$from, $from->copy()->addMonths(max(1, $months) - 1)->endOfMonth()];
    }

    /**
     * Kỳ phiếu của vai trò chứa tháng month/year.
     *
     * @return array{month: int, year: int, months: int, key: string, label: string, from: Carbon, to: Carbon}
     */
    public static function period(?string $role, int $month, int $year): array
    {
        $months = KpiCriterion::periodMonths($role);
        $start = $months === 3 ? intdiv($month - 1, 3) * 3 + 1 : $month;
        [$from, $to] = self::range($start, $year, $months);

        return [
            'month' => $start,
            'year' => $year,
            'months' => $months,
            'key' => sprintf('%04d-%02d', $year, $start),
            'label' => $months === 3 ? 'Quý '.(intdiv($start - 1, 3) + 1).'/'.$year : 'Tháng '.sprintf('%02d/%04d', $start, $year),
            'from' => $from,
            'to' => $to,
        ];
    }

    /** Kỳ phiếu của một nhân sự (theo vai trò KPI của người đó). */
    public static function periodFor(User $staff, int $month, int $year): array
    {
        return self::period(KpiCriterion::roleFor($staff), $month, $year);
    }

    /** 100.0 → 100 (mức đạt nguyên giữ kiểu số nguyên như levelFor). */
    private static function whole(float $value): int|float
    {
        return floor($value) === $value ? (int) $value : $value;
    }

    /** Nhân sự đang làm có vai trò KPI mà vai trò đó có ít nhất một tiêu chí đang áp dụng. */
    public function staffQuery(?string $role = null): Builder
    {
        $roles = KpiCriterion::active()->whereIn('role', $role ? [$role] : KpiCriterion::ROLES)->distinct()->pluck('role')->all();

        return User::query()->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $roles ?: ['__none__']));
    }

    /**
     * Tạo phiếu "Chờ duyệt" cho nhân sự chưa có phiếu của kỳ chứa tháng này (chạy hằng ngày; nhân sự mới vào giữa kỳ cũng có phiếu).
     */
    public function ensureSheets(int $month, int $year): int
    {
        $created = 0;
        $this->staffQuery()->with('roles')->orderBy('id')->each(function (User $user) use ($month, $year, &$created) {
            $period = self::periodFor($user, $month, $year);
            $evaluation = KpiEvaluation::firstOrCreate(
                ['user_id' => $user->id, 'month' => $period['month'], 'year' => $period['year']],
                ['period_months' => $period['months'], 'total_score' => 0, 'status' => KpiEvaluation::STATUS_PENDING]
            );
            $created += $evaluation->wasRecentlyCreated ? 1 : 0;
        });

        return $created;
    }

    /** Phiếu đã có của nhân sự cho kỳ chứa tháng này. */
    public static function evaluationFor(User $staff, int $month, int $year): ?KpiEvaluation
    {
        $period = self::periodFor($staff, $month, $year);

        return KpiEvaluation::with(['items', 'evaluator'])->where('user_id', $staff->id)
            ->where('month', $period['month'])->where('year', $period['year'])->first();
    }

    /**
     * Nội dung phiếu: từng tiêu chí (số liệu, mức đạt, tiền), tổng % đạt theo trọng số (mục chưa có số = 0), tiền KPI (Học vụ),
     * xếp loại A–E (GV part-time, phiếu quý). Có điều kiện loại trừ (vd. nghỉ dạy không phép) → tổng = 0.
     *
     * @return array{role: ?string, fund: ?float, period: array, lines: Collection, missing: int, total: float, amount: ?float, knockout: ?string, grade: ?array}
     */
    public function sheet(User $staff, int $month, int $year, ?KpiEvaluation $evaluation = null): array
    {
        $role = KpiCriterion::roleFor($staff);
        $period = self::period($role, $month, $year);
        $criteria = $role ? KpiCriterion::forRole($role)->active()->ordered()->get() : collect();
        // Phiếu đã duyệt theo bộ tiêu chí trước đây: hiện đúng các tiêu chí đã chấm (phiếu chờ duyệt dùng bộ hiện hành).
        if ($evaluation?->status === KpiEvaluation::STATUS_APPROVED) {
            $criteria = $evaluation->scoredCriteria($criteria)['criteria'];
        }
        $fund = $role === Roles::ACADEMIC_STAFF ? KpiCriterion::fund() : null;
        $items = $evaluation ? $evaluation->loadMissing('items')->items->keyBy('kpi_criterion_id') : collect();
        $frozen = $evaluation?->status === KpiEvaluation::STATUS_APPROVED;
        $weightTotal = (float) $criteria->sum('weight');
        $monthKeys = collect(range(0, $period['months'] - 1))->map(fn ($i) => $period['from']->copy()->addMonths($i)->format('Y-m'));

        $lines = $criteria->map(function (KpiCriterion $c) use ($items, $frozen, $staff, $period, $fund, $weightTotal, $monthKeys) {
            $item = $items->get($c->id);
            $cast = fn ($v) => is_numeric($v) ? ($c->isRate() ? round((float) $v, 2) : (int) $v) : null;
            $evidence = [];
            $auto = false;
            $level = null;
            if ($frozen) {
                $value = $cast($item?->actual);
                $evidence = $item?->evidence ?? [];
                $auto = $c->isAuto() && $item?->evidence !== null;
                $level = $item ? (float) $item->score : null;
            } else {
                $value = null;
                if ($c->isAuto()) {
                    $measured = $this->counter->measure($c->auto_source, $staff, $period['from'], $period['to']);
                    $evidence = $measured['evidence'];
                    // Nguồn tỉ lệ chưa có dữ liệu trong kỳ → người chấm điền tay.
                    $auto = $measured['value'] !== null || ! $c->isRateSource();
                    $value = $auto ? $cast($measured['value']) : null;
                }
                if (! $auto) {
                    $value = $cast($item?->actual);
                }
                if ($c->hasRule()) {
                    $level = match (true) {
                        $value === null => null,
                        // Phiếu quý: tính mức đạt từng tháng rồi lấy trung bình.
                        $auto && $c->per_month && $period['months'] > 1 => self::whole(round($monthKeys->avg(fn (string $key) => $c->levelFor(
                            count(array_filter($evidence, fn (array $e) => $e['counted'] && ($e['month'] ?? null) === $key))
                        )), 2)),
                        default => $c->levelFor($value),
                    };
                } else {
                    // Tiêu chí % cũ không có quy tắc: giữ % đã chấm.
                    $level = $item ? (float) $item->score : null;
                }
                if ($item?->critical_error) {
                    $level = 0.0;
                }
            }
            $maxAmount = $fund !== null && $weightTotal > 0 ? $fund * (float) $c->weight / $weightTotal : null;

            return [
                'criterion' => $c,
                'auto' => $auto,
                'value' => $value,
                'evidence' => $evidence,
                'level' => $level,
                'max_amount' => $maxAmount,
                'amount' => $maxAmount !== null && $level !== null ? $maxAmount * $level / 100 : null,
            ];
        });

        $knockout = $lines->first(fn ($l) => $l['criterion']->knockout && ($l['value'] ?? 0) > 0);
        $total = $weightTotal > 0 ? round($lines->sum(fn ($l) => ($l['level'] ?? 0) * (float) $l['criterion']->weight) / $weightTotal, 2) : 0.0;
        if ($knockout) {
            $total = 0.0;
        }
        if ($frozen) {
            $total = (float) $evaluation->total_score;
        }

        return [
            'role' => $role,
            'fund' => $fund,
            'period' => $period,
            'lines' => $lines,
            'missing' => $lines->filter(fn ($l) => $l['level'] === null)->count(),
            'total' => $total,
            'amount' => $fund !== null ? round($fund * $total / 100) : null,
            'knockout' => $knockout ? $knockout['criterion']->name : null,
            'grade' => KpiCriterion::hasGrades($role, $period['months']) ? KpiCriterion::gradeFor($total) : null,
        ];
    }
}
