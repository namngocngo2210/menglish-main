<?php

namespace App\Services;

use App\Models\ClassEnrollment;
use App\Models\CommissionAdjustment;
use App\Models\CommissionTier;
use App\Models\CrmCustomer;
use App\Models\StudentAttendance;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Hoa hồng tuyển sinh — nguồn tính DUY NHẤT cho bảng lương, BXH KPI và báo cáo CRM.
 *
 * Luật (A6, chốt 25/09/2026):
 * - Căn cứ = tổng tiền THỰC THU: phiếu thu đã duyệt (gồm cả tiền giáo trình, đồ dùng,
 *   phụ thu), không dùng giá trị hợp đồng (deal_value).
 * - Tính vào tháng phiếu được DUYỆT (tạm: approved_at nằm trong kỳ lương — chờ BA xác nhận
 *   "tháng thực thu").
 * - Chỉ khách mới. Diễn giải tạm (chờ BA): mọi phiếu thu thuộc KHOẢN HỌC PHÍ ĐẦU TIÊN
 *   (StudentTuition có id nhỏ nhất) của học viên được chuyển đổi từ khách CRM; phiếu của
 *   các khoản học phí sau (tái tục) không có hoa hồng. Phiếu không gắn khoản học phí chỉ
 *   tính khi được lập trước khi học viên có khoản học phí thứ hai.
 * - Sale nhận hoa hồng = sale phụ trách khách lúc chốt (crm_customers.commission_user_id,
 *   ghi nhận assigned_user_id tại thời điểm chốt; lead cũ chưa có thì dùng assigned_user_id).
 * - Không tính phiếu âm (hoàn/chuyển nhượng đi) và phiếu nhận chuyển nhượng (XFER-IN):
 *   tiền chuyển nhượng không phải tiền thực thu mới. Thu hồi khi hoàn phí đi qua
 *   CommissionAdjustment do người duyệt hoàn phí quyết định.
 */
class SalesCommissionService
{
    /** Hoàn phí khi học chưa tới 1 tháng thì gợi ý thu hồi hoa hồng. */
    public const CLAWBACK_SUGGEST_MONTHS = 1;

    /**
     * Doanh thu thực thu tính hoa hồng theo sale trong [start, end]: [user_id => số tiền].
     */
    public function collectedBySales(CarbonInterface $start, CarbonInterface $end, ?int $userId = null, ?Builder $customerScope = null): Collection
    {
        return $this->commissionableReceipts($start, $end, $userId, $customerScope)
            ->groupBy('commission_owner_id')
            ->map(fn (Collection $receipts) => (float) $receipts->sum('amount'));
    }

    /**
     * Các phiếu thu làm căn cứ hoa hồng trong kỳ, mỗi phiếu gắn thêm commission_owner_id.
     * $customerScope: truy vấn CrmCustomer đã lọc sẵn (chi nhánh / phạm vi xem của báo cáo CRM).
     */
    public function commissionableReceipts(CarbonInterface $start, CarbonInterface $end, ?int $userId = null, ?Builder $customerScope = null): Collection
    {
        $owners = $this->studentOwners($userId, $customerScope);
        if ($owners->isEmpty()) {
            return collect();
        }

        $tuitions = StudentTuition::whereIn('student_id', $owners->keys())
            ->orderBy('id')
            ->get(['id', 'student_id', 'created_at']);
        $tuitionsByStudent = $tuitions->groupBy('student_id');
        $studentOfTuition = $tuitions->pluck('student_id', 'id');

        return TuitionReceipt::query()
            ->where('status', TuitionReceipt::STATUS_APPROVED)
            ->where('amount', '>', 0)
            ->where(fn ($q) => $q->whereNull('transaction_code')->orWhere('transaction_code', 'not like', 'XFER-IN-%'))
            ->whereBetween('approved_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->where(fn ($q) => $q->whereIn('student_id', $owners->keys())->orWhereIn('student_tuition_id', $studentOfTuition->keys()))
            ->get()
            // Phiếu cũ có thể chỉ gắn khoản học phí mà thiếu student_id
            ->each(fn (TuitionReceipt $receipt) => $receipt->student_id ??= $studentOfTuition->get($receipt->student_tuition_id))
            ->filter(fn (TuitionReceipt $receipt) => $owners->has($receipt->student_id))
            ->filter(fn (TuitionReceipt $receipt) => $this->belongsToFirstTuition($receipt, $tuitionsByStudent->get($receipt->student_id, collect())))
            ->each(fn (TuitionReceipt $receipt) => $receipt->setAttribute('commission_owner_id', $owners->get($receipt->student_id)))
            ->values();
    }

    /**
     * Hoa hồng cho một mức doanh thu, theo bậc hiệu lực tại $asOf.
     *
     * @return array{tier: ?CommissionTier, tier_name: string, percent: float, bonus: float, amount: float}
     */
    public function commissionFor(float $revenue, CarbonInterface|string|null $asOf = null): array
    {
        $tier = $revenue > 0 ? CommissionTier::matchForRevenue($revenue, $asOf) : null;
        $percent = $tier ? (float) $tier->new_sale_percent : 0.0;
        $bonus = $tier ? (float) $tier->bonus_amount : 0.0;

        return [
            'tier' => $tier,
            'tier_name' => $tier?->tier_name ?? 'Chưa đạt mốc',
            'percent' => $percent,
            'bonus' => $bonus,
            'amount' => $tier ? round($revenue * $percent / 100 + $bonus, 2) : 0.0,
        ];
    }

    /** Sale nhận hoa hồng của một học viên (null = học viên không đến từ khách CRM). */
    public function ownerOfStudent(int $studentId): ?int
    {
        $customer = CrmCustomer::where('converted_student_id', $studentId)->latest('id')->first(['commission_user_id', 'assigned_user_id']);

        return $customer ? ($customer->commission_user_id ?? $customer->assigned_user_id) : null;
    }

    /**
     * Ngày bắt đầu học: buổi có mặt đầu tiên, hoặc ngày vào lớp (enrollment) nếu sớm hơn / chưa điểm danh.
     */
    public function studyStartDate(int $studentId): ?Carbon
    {
        $firstAttendance = StudentAttendance::where('student_id', $studentId)
            ->whereIn('status', ['present', 'late'])
            ->min('session_date');
        $firstEnrollment = ClassEnrollment::where('student_id', $studentId)->min('enrolled_at');

        $dates = collect([$firstAttendance, $firstEnrollment])->filter()->map(fn ($d) => Carbon::parse($d));

        return $dates->isEmpty() ? null : $dates->min();
    }

    /**
     * Gợi ý mặc định khi duyệt hoàn phí: học chưa tới 1 tháng (hoặc chưa học) → thu hồi.
     * Chuyển nhượng phí không bao giờ thu hồi.
     */
    public function suggestClawback(TuitionRefundRequest $refund, ?CarbonInterface $at = null): bool
    {
        if ($refund->type !== 'refund') {
            return false;
        }

        $start = $this->studyStartDate((int) $refund->student_id);
        $at ??= now();

        return $start === null || $start->gt($at->copy()->subMonthsNoOverflow(self::CLAWBACK_SUGGEST_MONTHS));
    }

    /**
     * Số hoa hồng gợi ý thu hồi: phần tiền hoàn (tối đa bằng số đã tính hoa hồng của học viên)
     * × % hoa hồng tuyển mới mà sale được hưởng ở tháng học viên đóng tiền gần nhất.
     * Thưởng vượt mốc của bậc không bị thu hồi.
     */
    public function suggestedClawbackAmount(TuitionRefundRequest $refund): float
    {
        $owner = $this->ownerOfStudent((int) $refund->student_id);
        if ($refund->type !== 'refund' || ! $owner) {
            return 0.0;
        }

        $receipts = $this->commissionableReceipts(Carbon::create(2000, 1, 1), now(), $owner)
            ->where('student_id', $refund->student_id);
        if ($receipts->isEmpty()) {
            return 0.0;
        }

        $base = min((float) $refund->refund_amount, (float) $receipts->sum('amount'));
        $month = Carbon::parse($receipts->max('approved_at'));
        $monthRevenue = (float) ($this->collectedBySales($month->copy()->startOfMonth(), $month->copy()->endOfMonth(), $owner)->get($owner) ?? 0);
        $percent = $this->commissionFor($monthRevenue, $month->copy()->endOfMonth())['percent'];

        return round($base * $percent / 100, 0);
    }

    /**
     * Ghi quyết định thu hồi hoa hồng khi hoàn phí được duyệt. $clawback = null → theo gợi ý.
     * Chuyển nhượng phí: luôn không thu hồi.
     */
    public function recordRefundDecision(TuitionRefundRequest $refund, ?bool $clawback, ?float $amount, ?User $approver): ?CommissionAdjustment
    {
        $owner = $this->ownerOfStudent((int) $refund->student_id);
        $clawback = $refund->type === 'refund' && ($clawback ?? $this->suggestClawback($refund));
        $amount = $clawback ? round(max(0, $amount ?? $this->suggestedClawbackAmount($refund)), 2) : 0.0;

        $refund->forceFill([
            'clawback_commission' => $refund->type === 'refund' ? $clawback : false,
            'clawback_amount' => $amount,
            'clawback_user_id' => $clawback && $owner ? $owner : null,
            'approved_at' => now(),
        ])->save();

        if (! $clawback || ! $owner || $amount <= 0) {
            return null;
        }

        return CommissionAdjustment::create([
            'user_id' => $owner,
            'tuition_refund_request_id' => $refund->id,
            'student_id' => $refund->student_id,
            'amount' => -$amount,
            'reason' => "Thu hồi hoa hồng do hoàn phí #{$refund->id} (học viên {$refund->student?->name})",
            'created_by' => $approver?->id,
        ]);
    }

    /** student_id => sale nhận hoa hồng, chỉ học viên được chuyển đổi từ khách CRM. */
    private function studentOwners(?int $userId, ?Builder $customerScope = null): Collection
    {
        return ($customerScope ? (clone $customerScope) : CrmCustomer::query())
            ->whereNotNull('converted_student_id')
            ->when($userId, fn ($q) => $q->where(fn ($owner) => $owner->where('commission_user_id', $userId)
                ->orWhere(fn ($legacy) => $legacy->whereNull('commission_user_id')->where('assigned_user_id', $userId))))
            ->orderBy('id')
            ->get(['converted_student_id', 'commission_user_id', 'assigned_user_id'])
            ->mapWithKeys(fn (CrmCustomer $customer) => [
                $customer->converted_student_id => $customer->commission_user_id ?? $customer->assigned_user_id,
            ])
            ->filter();
    }

    private function belongsToFirstTuition(TuitionReceipt $receipt, Collection $tuitions): bool
    {
        $first = $tuitions->first();
        if ($receipt->student_tuition_id) {
            return $first !== null && (int) $receipt->student_tuition_id === (int) $first->id;
        }

        $second = $tuitions->get(1);

        return $second === null || $receipt->created_at === null || $receipt->created_at->lt($second->created_at);
    }
}
