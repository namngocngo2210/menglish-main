<?php

namespace App\Services;

use App\Models\ClassEnrollment;
use App\Models\CommissionAdjustment;
use App\Models\CommissionItem;
use App\Models\CommissionTier;
use App\Models\CrmCustomer;
use App\Models\PayrollPeriod;
use App\Models\Student;
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
 * Luật (A6, chốt 25/09/2026; sửa 03/10/2026 theo yêu cầu "hoa hồng Học vụ % tăng tiến theo mốc"):
 * - Căn cứ = HỌC PHÍ THỰC THU: phiếu thu đã duyệt, KHÔNG gồm tiền sách / Thu khác — bỏ phụ thu của phiếu
 *   (surcharge_amount) và phần phí khác của khoản học phí (other_fees: sách, giáo trình…) theo tỉ lệ
 *   other_fees / final_amount. Không dùng giá trị hợp đồng (deal_value).
 * - Tính vào tháng phiếu được DUYỆT (tạm: approved_at nằm trong kỳ lương — chờ BA xác nhận
 *   "tháng thực thu").
 * - Chỉ khách mới. Diễn giải tạm (chờ BA): mọi phiếu thu thuộc KHOẢN HỌC PHÍ ĐẦU TIÊN
 *   (StudentTuition có id nhỏ nhất) của học viên được chuyển đổi từ khách CRM; phiếu của
 *   các khoản học phí sau (tái tục) không có hoa hồng. Phiếu không gắn khoản học phí chỉ
 *   tính khi được lập trước khi học viên có khoản học phí thứ hai.
 * - % hoa hồng TĂNG TIẾN THEO MỐC (Admin cấu hình ở màn Mốc hoa hồng): mỗi học viên mang % của mốc chứa
 *   THỨ TỰ chốt của mình trong tháng chốt của người phụ trách. VD mốc 1–5: 4%, từ 6: 3% → 5 HS đầu tháng
 *   được 4% học phí thu được, HS thứ 6 trở đi 3%. Thứ tự đếm lại từ 1 mỗi tháng; % lấy theo bảng mốc hiệu
 *   lực tại cuối kỳ phát sinh. Tự tính, không sửa tay.
 * - Gate kép theo từng khách: đủ 30 ngày từ ngày chốt VÀ đủ 3/3 mốc chăm sóc tháng đầu
 *   (FirstMonthCareService::careMilestonesCompleted). Chưa đạt → khoản hoa hồng HOÃN sang kỳ sau (sổ
 *   commission_items), trả ở kỳ đầu tiên gate đạt, % giữ theo kỳ phát sinh.
 * - Người nhận hoa hồng (Học vụ / người phụ trách) = người phụ trách khách lúc chốt (crm_customers.commission_user_id,
 *   ghi nhận assigned_user_id tại thời điểm chốt; lead cũ chưa có thì dùng assigned_user_id).
 * - Không tính phiếu âm (hoàn/chuyển nhượng đi) và phiếu nhận chuyển nhượng (XFER-IN):
 *   tiền chuyển nhượng không phải tiền thực thu mới. Thu hồi khi hoàn phí đi qua
 *   CommissionAdjustment do người duyệt hoàn phí quyết định; hủy hóa đơn sau khi kỳ lương
 *   đã duyệt cũng tạo CommissionAdjustment (recordCancellationClawback).
 */
class SalesCommissionService
{
    /** Hoàn phí khi học chưa tới 1 tháng thì gợi ý thu hồi hoa hồng. */
    public const CLAWBACK_SUGGEST_MONTHS = 1;

    /** [owner_id => [crm_customer_id => thứ tự chốt trong tháng]] — nhớ trong một lần tính. */
    private array $rankCache = [];

    /**
     * Doanh thu thực thu tính hoa hồng theo người phụ trách trong [start, end]: [user_id => số tiền] (toàn bộ tiền phiếu).
     */
    public function collectedBySales(CarbonInterface $start, CarbonInterface $end, ?int $userId = null, ?Builder $customerScope = null): Collection
    {
        return $this->commissionableReceipts($start, $end, $userId, $customerScope)
            ->groupBy('commission_owner_id')
            ->map(fn (Collection $receipts) => (float) $receipts->sum('amount'));
    }

    /**
     * Các phiếu thu làm căn cứ hoa hồng trong kỳ. Mỗi phiếu gắn thêm: commission_owner_id, commission_customer_id,
     * commission_base (học phí, không gồm sách / Thu khác), closing_rank (HS thứ mấy trong tháng chốt của người
     * phụ trách), commission_percent / commission_tier_name (mốc hiệu lực tại $end) và commission_amount.
     * $customerScope: truy vấn CrmCustomer đã lọc sẵn (chi nhánh / phạm vi xem của báo cáo CRM).
     */
    public function commissionableReceipts(CarbonInterface $start, CarbonInterface $end, ?int $userId = null, ?Builder $customerScope = null): Collection
    {
        $this->rankCache = [];
        $customers = $this->convertedCustomers($userId, $customerScope);
        if ($customers->isEmpty()) {
            return (new TuitionReceipt)->newCollection();
        }

        $tuitions = StudentTuition::whereIn('student_id', $customers->keys())
            ->orderBy('id')
            ->get(['id', 'student_id', 'final_amount', 'other_fees', 'created_at']);
        $tuitionsByStudent = $tuitions->groupBy('student_id');
        $tuitionById = $tuitions->keyBy('id');
        $rates = [];

        return TuitionReceipt::query()
            ->where('status', TuitionReceipt::STATUS_APPROVED)
            ->where('amount', '>', 0)
            ->where(fn ($q) => $q->whereNull('transaction_code')->orWhere('transaction_code', 'not like', 'XFER-IN-%'))
            ->whereBetween('approved_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->where(fn ($q) => $q->whereIn('student_id', $customers->keys())->orWhereIn('student_tuition_id', $tuitionById->keys()))
            ->get()
            // Phiếu cũ có thể chỉ gắn khoản học phí mà thiếu student_id
            ->each(fn (TuitionReceipt $receipt) => $receipt->student_id ??= $tuitionById->get($receipt->student_tuition_id)?->student_id)
            ->filter(fn (TuitionReceipt $receipt) => $customers->has($receipt->student_id))
            ->filter(fn (TuitionReceipt $receipt) => $this->belongsToFirstTuition($receipt, $tuitionsByStudent->get($receipt->student_id, collect())))
            ->each(function (TuitionReceipt $receipt) use ($customers, $tuitionById, $end, &$rates) {
                $customer = $customers->get($receipt->student_id);
                $rank = $this->closingRank($customer);
                $rates[$rank] ??= $this->rateForRank($rank, $end);
                $base = $this->tuitionBase($receipt, $tuitionById->get($receipt->student_tuition_id));

                $receipt->setAttribute('commission_owner_id', $customer->commission_owner_id);
                $receipt->setAttribute('commission_customer_id', $customer->id);
                $receipt->setAttribute('commission_base', $base);
                $receipt->setAttribute('closing_rank', $rank);
                $receipt->setAttribute('commission_percent', $rates[$rank]['percent']);
                $receipt->setAttribute('commission_tier_name', $rates[$rank]['tier_name']);
                $receipt->setAttribute('commission_amount', round($base * $rates[$rank]['percent'] / 100, 0));
            })
            ->values();
    }

    /**
     * Phần HỌC PHÍ của phiếu (căn cứ hoa hồng): bỏ phụ thu của phiếu và phần phí khác (sách, giáo trình…) của khoản
     * học phí theo tỉ lệ other_fees / final_amount. Phiếu chỉ thu phụ thu → 0.
     */
    public function tuitionBase(TuitionReceipt $receipt, ?StudentTuition $tuition): float
    {
        $portion = max(0.0, (float) $receipt->amount - (float) $receipt->surcharge_amount);
        $final = (float) ($tuition?->final_amount ?? 0);
        $otherFees = (float) ($tuition?->other_fees ?? 0);
        if ($portion <= 0 || $otherFees <= 0 || $final <= 0) {
            return round($portion, 0);
        }

        return round($portion * max(0.0, min(1.0, ($final - $otherFees) / $final)), 0);
    }

    /**
     * Thứ tự chốt (1 = HS đầu tiên) của khách trong THÁNG CHỐT của người phụ trách nhận hoa hồng, đếm trên mọi chi
     * nhánh, theo giờ chốt (converted_at; khách cũ chưa ghi thì dùng ngày tạo) rồi theo id.
     */
    public function closingRank(CrmCustomer $customer): int
    {
        $owner = (int) ($customer->commission_user_id ?? $customer->assigned_user_id);
        if (! array_key_exists($owner, $this->rankCache)) {
            $months = CrmCustomer::query()
                ->whereNotNull('converted_student_id')
                ->where(fn ($q) => $q->where('commission_user_id', $owner)
                    ->orWhere(fn ($legacy) => $legacy->whereNull('commission_user_id')->where('assigned_user_id', $owner)))
                ->get(['id', 'converted_at', 'created_at'])
                ->groupBy(fn (CrmCustomer $c) => ($c->converted_at ?? $c->created_at)?->format('Y-m'));
            $ranks = [];
            foreach ($months as $month) {
                $ordered = $month->sortBy(fn (CrmCustomer $c) => [($c->converted_at ?? $c->created_at)?->getTimestamp() ?? 0, $c->id])->values();
                foreach ($ordered as $i => $c) {
                    $ranks[$c->id] = $i + 1;
                }
            }
            $this->rankCache[$owner] = $ranks;
        }

        return (int) ($this->rankCache[$owner][$customer->id] ?? 1);
    }

    /**
     * % hoa hồng của học viên thứ $rank trong tháng chốt, theo bảng mốc hiệu lực tại $asOf.
     *
     * @return array{tier: ?CommissionTier, tier_name: string, percent: float}
     */
    public function rateForRank(int $rank, CarbonInterface|string|null $asOf = null): array
    {
        $tier = CommissionTier::matchForStudents(max(1, $rank), $asOf);

        return [
            'tier' => $tier,
            'tier_name' => $tier?->tier_name ?? 'Chưa cấu hình mốc',
            'percent' => $tier ? (float) $tier->new_sale_percent : 0.0,
        ];
    }

    /**
     * Mốc hiện tại của người đã chốt $closed HS trong tháng (mốc của HS gần nhất; chưa chốt ai = mốc của HS đầu tiên)
     * và mốc kế tiếp: còn bao nhiêu HS nữa thì sang mốc có % khác.
     *
     * @return array{tier_name: string, percent: float, range: ?string, next_percent: ?float, next_range: ?string, to_next: ?int}
     */
    public function milestoneFor(int $closed, CarbonInterface|string|null $asOf = null): array
    {
        $current = $this->rateForRank(max(1, $closed), $asOf);
        $next = $current['tier']?->max_students !== null
            ? CommissionTier::byStudents()->effectiveAt($asOf)->where('min_students', '>', $current['tier']->max_students)->orderBy('min_students')->first()
            : null;

        return [
            'tier_name' => $current['tier_name'],
            'percent' => $current['percent'],
            'range' => $current['tier']?->student_range_label,
            'next_percent' => $next ? (float) $next->new_sale_percent : null,
            'next_range' => $next?->student_range_label,
            'to_next' => $next ? max(1, (int) $next->min_students - $closed) : null,
        ];
    }

    /**
     * Hoa hồng phát sinh trong [start, end] theo người phụ trách (trước gate kép — số trả thực tế theo phiếu lương):
     * [user_id => [collected (tổng tiền phiếu), base (học phí tính HH), amount, students, closed, mốc hiện tại…]].
     * Số HS chốt và mốc tính trên mọi chi nhánh như bảng lương; tiền chỉ trong $customerScope.
     */
    public function summaryByOwner(CarbonInterface $start, CarbonInterface $end, ?int $userId = null, ?Builder $customerScope = null): Collection
    {
        $receipts = $this->commissionableReceipts($start, $end, $userId, $customerScope)->groupBy('commission_owner_id');
        $closed = $this->closedCountsBySales($start, $end);
        $owners = $receipts->keys()->merge($closed->keys())->map(fn ($id) => (int) $id)->unique()
            ->when($userId, fn (Collection $ids) => $ids->filter(fn (int $id) => $id === $userId));

        return $owners->mapWithKeys(function (int $owner) use ($receipts, $closed, $end) {
            $mine = $receipts->get($owner, collect());
            $count = (int) ($closed->get($owner) ?? 0);

            return [$owner => [
                'collected' => (float) $mine->sum('amount'),
                'base' => (float) $mine->sum('commission_base'),
                'amount' => (float) $mine->sum('commission_amount'),
                'students' => $mine->pluck('student_id')->unique()->count(),
                'closed' => $count,
                ...$this->milestoneFor($count, $end),
            ]];
        });
    }

    /**
     * Bảng hoa hồng TẠM TÍNH trong tháng của một người phụ trách (trang cá nhân): số HS đã chốt, mốc hiện tại / kế tiếp,
     * bảng mốc, và từng học viên (chốt trong tháng hoặc có học phí thu trong tháng) kèm HS thứ mấy, %, học phí khóa
     * (không sách), đã thu đến nay, hoa hồng khi thu đủ và hoa hồng đã ghi nhận theo tiền về.
     * Hoa hồng chỉ ghi nhận khi phiếu thu được duyệt (hệ thống ghi nhận tiền về); số thực trả nằm ở bảng lương.
     */
    public function statementFor(int $userId, CarbonInterface $month): array
    {
        $start = Carbon::parse($month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        // Mọi phiếu đã duyệt tới cuối tháng (để biết đã thu bao nhiêu / đủ chưa); phiếu trong tháng = vào lương tháng này.
        $allReceipts = $this->commissionableReceipts(Carbon::create(2000, 1, 1), $end, $userId)->groupBy('student_id');
        $inMonth = fn (TuitionReceipt $receipt) => $receipt->approved_at !== null && $receipt->approved_at->between($start, $end);
        $closedHere = CrmCustomer::query()
            ->whereNotNull('converted_student_id')
            ->where(fn ($q) => $q->where('commission_user_id', $userId)
                ->orWhere(fn ($legacy) => $legacy->whereNull('commission_user_id')->where('assigned_user_id', $userId)))
            ->whereBetween('converted_at', [$start, $end])
            ->get(['id', 'name', 'converted_student_id', 'commission_user_id', 'assigned_user_id', 'converted_at', 'created_at'])
            ->keyBy('converted_student_id');
        $closed = $closedHere->count();
        $studentIds = $allReceipts->filter(fn (Collection $receipts) => $receipts->contains($inMonth))->keys()
            ->merge($closedHere->keys())->unique()->values();
        $studentNames = Student::whereIn('id', $studentIds)->pluck('name', 'id');
        // Khoản học phí đầu tiên (khoản tính hoa hồng khách mới): học phí khóa không gồm sách / Thu khác.
        $firstTuitions = StudentTuition::whereIn('student_id', $studentIds)->orderBy('id')
            ->get(['id', 'student_id', 'final_amount', 'other_fees'])
            ->groupBy('student_id')->map(fn (Collection $tuitions) => $tuitions->first());

        $rows = $studentIds->map(function ($studentId) use ($allReceipts, $inMonth, $closedHere, $studentNames, $firstTuitions, $end) {
            $all = $allReceipts->get($studentId, collect());
            $mine = $all->filter($inMonth);
            $first = $all->first();
            $customer = $closedHere->get($studentId);
            $rank = $first ? (int) $first->closing_rank : $this->closingRank($customer);
            $percent = $first ? (float) $first->commission_percent : $this->rateForRank($rank, $end)['percent'];
            $closedAt = $customer?->converted_at ?? CrmCustomer::whereKey($first?->commission_customer_id)->value('converted_at');
            $tuition = $firstTuitions->get($studentId);
            $tuitionBase = $tuition ? max(0.0, (float) $tuition->final_amount - (float) $tuition->other_fees) : null;
            $paidTotal = (float) $all->sum('commission_base');

            return [
                'student' => $studentNames->get($studentId) ?? $customer?->name ?? '—',
                'rank' => $rank,
                'closed_at' => $closedAt ? Carbon::parse($closedAt)->format('d/m/Y') : null,
                'closed_this_month' => $customer !== null,
                'percent' => $percent,
                'collected' => (float) $mine->sum('amount'),
                'base' => (float) $mine->sum('commission_base'),
                'amount' => (float) $mine->sum('commission_amount'),
                'tuition' => $tuitionBase,
                'paid_total' => $paidTotal,
                'remaining' => $tuitionBase !== null ? max(0.0, $tuitionBase - $paidTotal) : null,
                'fully_paid' => $tuitionBase !== null && $tuitionBase > 0 && $paidTotal >= $tuitionBase,
                'expected' => $tuitionBase !== null ? round($tuitionBase * $percent / 100, 0) : null,
                'earned_total' => (float) $all->sum('commission_amount'),
            ];
        })->sortBy(fn (array $row) => [$row['closed_this_month'] ? 0 : 1, $row['rank']])->values();

        $milestone = $this->milestoneFor($closed, $end);
        $closedRows = $rows->where('closed_this_month', true);

        return [
            'month_label' => $start->format('m/Y'),
            'closed' => $closed,
            ...$milestone,
            'tiers' => CommissionTier::byStudents()->effectiveAt($end)->orderBy('min_students')->get()
                ->map(fn (CommissionTier $tier) => [
                    'range' => $tier->student_range_label,
                    'percent' => (float) $tier->new_sale_percent,
                    'current' => $tier->student_range_label === $milestone['range'],
                ])->values()->all(),
            'collected' => (float) $rows->sum('collected'),
            'base' => (float) $rows->sum('base'),
            'amount' => (float) $rows->sum('amount'),
            // Tạm tính khi thu đủ học phí của các HS chốt trong tháng; đã ghi nhận = theo tiền đã về của các HS đó.
            'expected' => (float) $closedRows->sum('expected'),
            'earned_closed' => (float) $closedRows->sum('earned_total'),
            'fully_paid' => $closedRows->where('fully_paid', true)->count(),
            'rows' => $rows->all(),
        ];
    }

    /**
     * Số HS chốt trong [start, end] (khách CRM chuyển thành học viên, converted_at trong kỳ): [user_id => số].
     */
    public function closedCountsBySales(CarbonInterface $start, CarbonInterface $end, ?Builder $customerScope = null): Collection
    {
        return ($customerScope ? (clone $customerScope) : CrmCustomer::query())
            ->whereNotNull('converted_student_id')
            ->whereBetween('converted_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->get(['commission_user_id', 'assigned_user_id'])
            ->map(fn (CrmCustomer $customer) => $customer->commission_user_id ?? $customer->assigned_user_id)
            ->filter()
            ->countBy()
            ->map(fn ($count) => (int) $count);
    }

    public function closedCountFor(int $userId, CarbonInterface $start, CarbonInterface $end): int
    {
        return (int) ($this->closedCountsBySales($start, $end)->get($userId) ?? 0);
    }

    // ───────────── Sổ hoa hồng & gate kép ─────────────

    /**
     * Ghi sổ các khoản hoa hồng phát sinh trong kỳ (phiếu thu khách mới duyệt trong kỳ): căn cứ = học phí của phiếu,
     * % theo mốc chứa thứ tự chốt của học viên trong tháng chốt (bảng mốc hiệu lực tại cuối kỳ).
     * Khoản chưa trả được cập nhật lại mỗi lần tính; phiếu không còn là căn cứ (bị hủy / từ chối) thì khoản
     * chưa trả bị hủy (void). Khoản đã trả không bao giờ bị sửa.
     */
    public function syncItemsForPeriod(PayrollPeriod $period): void
    {
        $start = $period->start_date->copy();
        $end = $period->end_date->copy();
        // Phiếu chỉ thu sách / Thu khác (học phí = 0) không có khoản hoa hồng.
        $receipts = $this->commissionableReceipts($start, $end)->filter(fn (TuitionReceipt $receipt) => $receipt->commission_base > 0)->values();
        $closedCounts = $this->closedCountsBySales($start, $end);
        $studentIds = $receipts->pluck('student_id')->unique()->values()->all();
        $customers = CrmCustomer::whereIn('converted_student_id', $studentIds)
            ->orderBy('id')->get(['id', 'converted_student_id', 'converted_at'])
            ->keyBy('converted_student_id');
        $closingDates = app(FirstMonthCareService::class)->closingDates(Student::whereIn('id', $studentIds)->get(['id', 'created_at']));
        $existing = CommissionItem::with('payrollRecord.period')->whereIn('tuition_receipt_id', $receipts->modelKeys())->get()->keyBy('tuition_receipt_id');

        foreach ($receipts as $receipt) {
            $item = $existing->get($receipt->id);
            // Khoản đã trả không sửa; khoản đã hủy (hủy hóa đơn / hoàn phí có thu hồi) không mở lại.
            if ($item && ($item->isPaidOut() || $item->status === CommissionItem::STATUS_VOID)) {
                continue;
            }

            $owner = (int) $receipt->commission_owner_id;
            $customer = $customers->get($receipt->student_id);

            $attributes = [
                'user_id' => $owner,
                'student_id' => $receipt->student_id,
                'crm_customer_id' => $customer?->id,
                'base_amount' => (float) $receipt->commission_base,
                'percent' => (float) $receipt->commission_percent,
                'amount' => (float) $receipt->commission_amount,
                'closed_count' => (int) ($closedCounts->get($owner) ?? 0),
                'closing_rank' => (int) $receipt->closing_rank,
                'earned_period_start' => $start->toDateString(),
                'earned_period_end' => $end->toDateString(),
                'closed_at' => $customer?->converted_at ?? ($closingDates[(int) $receipt->student_id] ?? null),
            ];

            if ($item) {
                $item->fill($attributes)->save();
            } else {
                CommissionItem::create($attributes + ['tuition_receipt_id' => $receipt->id, 'status' => CommissionItem::STATUS_DEFERRED]);
            }
        }

        // Phiếu phát sinh trong kỳ nhưng không còn là căn cứ (hủy hóa đơn, thành khoản tái tục…) → hủy khoản chưa trả.
        CommissionItem::open()
            ->with('payrollRecord.period')
            ->whereDate('earned_period_start', $start->toDateString())
            ->whereNotIn('tuition_receipt_id', $receipts->modelKeys() ?: [0])
            ->get()
            ->reject(fn (CommissionItem $item) => $item->isPaidOut())
            ->each(fn (CommissionItem $item) => $item->update([
                'status' => CommissionItem::STATUS_VOID, 'payroll_record_id' => null,
                'deferred_reason' => 'Phiếu thu không còn là căn cứ hoa hồng',
            ]));

        // Khoản hoãn từ kỳ trước mà phiếu đã bị hủy / không còn duyệt → hủy.
        CommissionItem::open()
            ->whereNull('payroll_record_id')
            ->whereDate('earned_period_end', '<', $start->toDateString())
            ->whereHas('receipt', fn ($q) => $q->where('status', '!=', TuitionReceipt::STATUS_APPROVED))
            ->update(['status' => CommissionItem::STATUS_VOID, 'deferred_reason' => 'Phiếu thu đã bị hủy / không còn duyệt']);
    }

    /**
     * Gate kép của một khoản tại cuối kỳ: đủ N ngày từ ngày chốt VÀ đủ số mốc chăm sóc (trạng thái hiện tại).
     *
     * @return array{passes: bool, reason: ?string, days_ok: bool, milestones: int}
     */
    public function gateFor(CommissionItem $item, CarbonInterface $asOf, array &$milestoneCache = []): array
    {
        $days = (int) config('payroll.commission.gate_days', 30);
        $needed = (int) config('payroll.commission.gate_milestones', 3);

        $closedAt = $item->closed_at ? Carbon::parse($item->closed_at)->startOfDay() : null;
        $daysOk = $closedAt !== null && $closedAt->copy()->addDays($days)->lte($asOf->copy()->endOfDay());

        $key = $item->crm_customer_id ? 'c'.$item->crm_customer_id : 's'.$item->student_id;
        if (! array_key_exists($key, $milestoneCache)) {
            $subject = $item->customer ?? $item->student;
            $milestoneCache[$key] = $subject ? app(FirstMonthCareService::class)->careMilestonesCompleted($subject) : 0;
        }
        $milestones = (int) $milestoneCache[$key];

        $reasons = [];
        if (! $daysOk) {
            $reasons[] = $closedAt
                ? 'chưa đủ '.$days.' ngày từ ngày chốt '.$closedAt->format('d/m/Y').' (đủ ngày '.$closedAt->copy()->addDays($days)->format('d/m/Y').')'
                : 'chưa xác định ngày chốt';
        }
        if ($milestones < $needed) {
            $reasons[] = 'chăm sóc tháng đầu mới '.$milestones.'/'.$needed.' mốc';
        }

        return [
            'passes' => $reasons === [],
            'reason' => $reasons === [] ? null : 'Hoãn: '.implode('; ', $reasons),
            'days_ok' => $daysOk,
            'milestones' => $milestones,
        ];
    }

    /**
     * Xét gate cho các khoản chưa trả (chưa gắn phiếu lương) phát sinh tới cuối kỳ: đạt → trả trong kỳ,
     * chưa đạt → hoãn kèm lý do. Trả về [user_id => ['payable' => Collection, 'deferred' => Collection]].
     */
    public function resolveForPeriod(PayrollPeriod $period): Collection
    {
        $end = $period->end_date->copy();
        $cache = [];

        return CommissionItem::open()
            ->with(['customer', 'student'])
            ->whereNull('payroll_record_id')
            ->whereDate('earned_period_end', '<=', $end->toDateString())
            ->orderBy('earned_period_start')->orderBy('id')
            ->get()
            ->groupBy('user_id')
            ->map(function (Collection $items) use ($end, &$cache) {
                $payable = collect();
                $deferred = collect();
                foreach ($items as $item) {
                    $gate = $this->gateFor($item, $end, $cache);
                    $item->deferred_reason = $gate['reason'];
                    $gate['passes'] ? $payable->push($item) : $deferred->push($item);
                }

                return ['payable' => $payable, 'deferred' => $deferred];
            });
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
     * Số hoa hồng gợi ý thu hồi: phần tiền hoàn (tối đa bằng căn cứ các khoản hoa hồng ĐÃ TRẢ của học viên)
     * × % của khoản đã trả gần nhất. Khoản còn hoãn (chưa trả) không tính ở đây — duyệt có thu hồi sẽ hủy chúng.
     */
    public function suggestedClawbackAmount(TuitionRefundRequest $refund): float
    {
        $owner = $this->ownerOfStudent((int) $refund->student_id);
        if ($refund->type !== 'refund' || ! $owner) {
            return 0.0;
        }

        $paid = CommissionItem::with('payrollRecord.period')
            ->where('student_id', $refund->student_id)
            ->where('user_id', $owner)
            ->where('status', '!=', CommissionItem::STATUS_VOID)
            ->orderBy('id')
            ->get()
            ->filter(fn (CommissionItem $item) => $item->isPaidOut());
        if ($paid->isEmpty()) {
            return 0.0;
        }

        $base = min((float) $refund->refund_amount, (float) $paid->sum('base_amount'));

        return round($base * (float) $paid->last()->percent / 100, 0);
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

        if ($clawback && $owner) {
            // Khoản hoa hồng của học viên còn HOÃN (chưa trả) → hủy, không trả ở kỳ sau.
            CommissionItem::open()->where('student_id', $refund->student_id)->where('user_id', $owner)
                ->get()->reject(fn (CommissionItem $item) => $item->isPaidOut())
                ->each(fn (CommissionItem $item) => $item->update([
                    'status' => CommissionItem::STATUS_VOID, 'payroll_record_id' => null,
                    'deferred_reason' => "Hủy do duyệt hoàn phí #{$refund->id} có thu hồi hoa hồng",
                ]));
        }

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

    /**
     * Hủy hóa đơn SAU KHI hoa hồng của phiếu đã được TRẢ (kỳ trả đã duyệt/chi): kỳ đã khóa không tính lại được →
     * tạo khoản thu hồi (CommissionAdjustment âm = đúng số hoa hồng đã trả của phiếu) trừ ở lần tính lương kế
     * tiếp của sale, cùng cơ chế thu hồi khi hoàn phí. Phiếu có hoa hồng còn hoãn / chưa trả → hủy khoản trong
     * sổ, không tạo thu hồi (lần tính lại tự loại phiếu).
     *
     * Gọi TRƯỚC khi đổi trạng thái phiếu sang cancelled.
     */
    public function recordCancellationClawback(TuitionReceipt $receipt, ?User $approver, string $invoiceNumber): ?CommissionAdjustment
    {
        if ($receipt->status !== TuitionReceipt::STATUS_APPROVED || (float) $receipt->amount <= 0 || ! $receipt->approved_at) {
            return null;
        }

        $item = CommissionItem::with('payrollRecord.period')->where('tuition_receipt_id', $receipt->id)->first();
        if (! $item || $item->status === CommissionItem::STATUS_VOID) {
            return null;
        }

        if (! $item->isPaidOut()) {
            $item->update([
                'status' => CommissionItem::STATUS_VOID, 'payroll_record_id' => null,
                'deferred_reason' => "Hủy do hủy hóa đơn {$invoiceNumber}",
            ]);

            return null;
        }

        $amount = round((float) $item->amount, 0);
        if ($amount <= 0) {
            return null;
        }
        $period = $item->payrollRecord?->period;

        return CommissionAdjustment::create([
            'user_id' => $item->user_id,
            'student_id' => $item->student_id,
            'amount' => -$amount,
            'reason' => "Thu hồi hoa hồng do hủy hóa đơn {$invoiceNumber} (phiếu {$receipt->receipt_number}) sau khi kỳ lương "
                .($period ? ($period->title ?: $period->code) : 'trả hoa hồng').' đã duyệt',
            'created_by' => $approver?->id,
        ]);
    }

    /**
     * student_id => khách CRM đã chuyển đổi (kèm commission_owner_id = người nhận hoa hồng), chỉ học viên đến từ CRM.
     */
    private function convertedCustomers(?int $userId, ?Builder $customerScope = null): Collection
    {
        return ($customerScope ? (clone $customerScope) : CrmCustomer::query())
            ->whereNotNull('converted_student_id')
            ->when($userId, fn ($q) => $q->where(fn ($owner) => $owner->where('commission_user_id', $userId)
                ->orWhere(fn ($legacy) => $legacy->whereNull('commission_user_id')->where('assigned_user_id', $userId))))
            ->orderBy('id')
            ->get(['id', 'converted_student_id', 'commission_user_id', 'assigned_user_id', 'converted_at', 'created_at'])
            ->each(fn (CrmCustomer $customer) => $customer->setAttribute('commission_owner_id', $customer->commission_user_id ?? $customer->assigned_user_id))
            ->filter(fn (CrmCustomer $customer) => $customer->commission_owner_id)
            ->keyBy('converted_student_id');
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
