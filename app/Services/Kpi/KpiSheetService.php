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
 * Phiếu KPI tháng: mỗi nhân sự có vai trò được chấm KPI (và vai trò đó có tiêu chí đang áp dụng) một phiếu mỗi tháng.
 * Tiêu chí có nguồn tự động lấy số từ KpiAutoCounter; tiêu chí điền tay lấy số người chấm đã điền (kpi_evaluation_items.actual).
 * Phiếu đã duyệt dùng số đã chốt lúc duyệt, không đếm lại.
 */
class KpiSheetService
{
    public function __construct(private KpiAutoCounter $counter) {}

    /** @return array{0: Carbon, 1: Carbon} đầu và cuối tháng */
    public static function range(int $month, int $year): array
    {
        $from = Carbon::create($year, $month, 1)->startOfDay();

        return [$from, $from->copy()->endOfMonth()];
    }

    /** Nhân sự đang làm có vai trò KPI mà vai trò đó có ít nhất một tiêu chí đang áp dụng. */
    public function staffQuery(?string $role = null): Builder
    {
        $roles = KpiCriterion::active()->whereIn('role', $role ? [$role] : KpiCriterion::ROLES)->distinct()->pluck('role')->all();

        return User::query()->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $roles ?: ['__none__']));
    }

    /** Tạo phiếu "Chờ duyệt" cho nhân sự chưa có phiếu của tháng (chạy hằng ngày; nhân sự mới vào giữa tháng cũng có phiếu). */
    public function ensureSheets(int $month, int $year): int
    {
        $existing = KpiEvaluation::where('month', $month)->where('year', $year)->pluck('user_id')->all();
        $created = 0;
        $this->staffQuery()->whereNotIn('id', $existing)->orderBy('id')->each(function (User $user) use ($month, $year, &$created) {
            KpiEvaluation::create(['user_id' => $user->id, 'month' => $month, 'year' => $year, 'total_score' => 0, 'status' => KpiEvaluation::STATUS_PENDING]);
            $created++;
        });

        return $created;
    }

    /**
     * Nội dung phiếu: từng tiêu chí (số liệu, mức đạt, tiền), tổng % đạt theo trọng số (mục chưa có số = 0) và tiền KPI.
     *
     * @return array{role: ?string, fund: ?float, lines: Collection, missing: int, total: float, amount: ?float}
     */
    public function sheet(User $staff, int $month, int $year, ?KpiEvaluation $evaluation = null): array
    {
        $role = KpiCriterion::roleFor($staff);
        $criteria = $role ? KpiCriterion::forRole($role)->active()->ordered()->get() : collect();
        // Phiếu đã duyệt theo bộ tiêu chí trước đây: hiện đúng các tiêu chí đã chấm (phiếu chờ duyệt dùng bộ hiện hành).
        if ($evaluation?->status === KpiEvaluation::STATUS_APPROVED) {
            $criteria = $evaluation->scoredCriteria($criteria)['criteria'];
        }
        $fund = $role === Roles::ACADEMIC_STAFF ? KpiCriterion::fund() : null;
        $items = $evaluation ? $evaluation->loadMissing('items')->items->keyBy('kpi_criterion_id') : collect();
        $frozen = $evaluation?->status === KpiEvaluation::STATUS_APPROVED;
        [$from, $to] = self::range($month, $year);
        $weightTotal = (float) $criteria->sum('weight');

        $lines = $criteria->map(function (KpiCriterion $c) use ($items, $frozen, $staff, $from, $to, $fund, $weightTotal) {
            $item = $items->get($c->id);
            $evidence = [];
            if ($c->isAuto()) {
                $evidence = $frozen && $item?->evidence !== null ? $item->evidence : $this->counter->evidence($c->auto_source, $staff, $from, $to);
                $value = $frozen && is_numeric($item?->actual) ? (int) $item->actual : KpiAutoCounter::countOf($evidence);
            } else {
                $value = is_numeric($item?->actual) ? (int) $item->actual : null;
            }
            // Tiêu chí cũ không đo bằng số lần (không có ngưỡng): giữ % đã chấm.
            $level = $c->isCountBased()
                ? ($value === null ? null : $c->levelForCount($value))
                : ($item ? (float) $item->score : null);
            if ($item?->critical_error) {
                $level = 0;
            }
            $maxAmount = $fund !== null && $weightTotal > 0 ? $fund * (float) $c->weight / $weightTotal : null;

            return [
                'criterion' => $c,
                'auto' => $c->isAuto(),
                'value' => $value,
                'evidence' => $evidence,
                'level' => $level,
                'max_amount' => $maxAmount,
                'amount' => $maxAmount !== null && $level !== null ? $maxAmount * $level / 100 : null,
            ];
        });

        $total = $weightTotal > 0 ? round($lines->sum(fn ($l) => ($l['level'] ?? 0) * (float) $l['criterion']->weight) / $weightTotal, 2) : 0.0;
        if ($frozen) {
            $total = (float) $evaluation->total_score;
        }

        return [
            'role' => $role,
            'fund' => $fund,
            'lines' => $lines,
            'missing' => $lines->filter(fn ($l) => $l['level'] === null)->count(),
            'total' => $total,
            'amount' => $fund !== null ? round($fund * $total / 100) : null,
        ];
    }
}
