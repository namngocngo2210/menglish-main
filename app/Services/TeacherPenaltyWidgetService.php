<?php

namespace App\Services;

use App\Models\PayrollPeriod;
use App\Models\Penalty;
use App\Models\StaffAttendance;
use App\Models\User;

/**
 * Widget "Vi phạm / khoản trừ mới" của Trang chủ GV (US #trang-chu-tong-quan R-14, AC-R20, TK-2026-0071):
 * một số gộp = biên bản vi phạm còn cần xử lý (chờ giải trình / đã giải trình / đã xác nhận, chưa quyết phạt)
 * + khoản trừ mới của kỳ lương hiện tại (biên bản đã quyết phạt, sẽ trừ ở kỳ này theo Penalty::scopeDeductibleFor).
 * Kỳ lương hiện tại = kỳ có khoảng ngày chứa hôm nay; chưa có kỳ thì lấy tháng dương lịch hiện tại.
 * Đi muộn tự động của chấm công đã trừ theo phút trên phiếu lương nên không đếm lại (khớp bảng lương).
 */
class TeacherPenaltyWidgetService
{
    /** Trạng thái còn chờ xử lý, chưa thành khoản trừ. */
    public const PENDING_STATUSES = ['pending', 'explained', 'confirmed'];

    /** @return array{violations: int, deductions: int, deduction_amount: float, total: int, period_label: string, url: string} */
    public function forTeacher(User $teacher): array
    {
        $base = fn () => Penalty::withoutViewerScope()->where('user_id', $teacher->id);

        $violations = $base()->whereIn('status', self::PENDING_STATUSES)->count();

        $period = PayrollPeriod::query()->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())
            ->orderByDesc('id')->first();
        if ($period) {
            $deductions = $base()->deductibleFor($period)->get(['amount']);
            $label = 'Kỳ lương '.sprintf('%02d/%d', $period->month, $period->year);
        } else {
            $deductions = $base()->where('status', 'fined')
                ->whereRaw('DATE(COALESCE(deduct_from_date, violation_date)) >= ?', [today()->startOfMonth()->toDateString()])
                ->whereRaw('DATE(COALESCE(deduct_from_date, violation_date)) <= ?', [today()->endOfMonth()->toDateString()])
                ->whereNotIn('id', StaffAttendance::query()->whereNotNull('penalty_id')->select('penalty_id'))
                ->get(['amount']);
            $label = 'Tháng '.today()->format('m/Y');
        }

        return [
            'violations' => $violations,
            'deductions' => $deductions->count(),
            'deduction_amount' => (float) $deductions->sum('amount'),
            'total' => $violations + $deductions->count(),
            'period_label' => $label,
            'url' => route('penalties.index'),
        ];
    }
}
