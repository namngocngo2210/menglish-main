<?php

namespace App\Services;

use App\Models\CommissionItem;
use App\Models\CommissionTier;
use App\Models\CrmCustomer;
use App\Models\PayrollPeriod;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Hoa hồng tuyển sinh — nguồn tính DUY NHẤT cho bảng lương, BXH KPI và báo cáo CRM.
 *
 * Luật Q7 (TK-2026-0021):
 * - Căn cứ = học phí thực thu từ phiếu đã duyệt, không gồm sách / Thu khác / phụ thu;
 *   bỏ surcharge_amount và phần other_fees / final_amount theo tỉ lệ, không dùng deal_value.
 * - Chỉ khách CRM mới: phiếu thuộc StudentTuition đầu tiên (id nhỏ nhất); phiếu cũ không gắn khoản
 *   học phí chỉ tính khi được lập trước khoản học phí thứ hai. Không tính phiếu âm / XFER-IN.
 * - Ngày chốt = approved_at sớm nhất của phiếu dương đã duyệt thuộc khoản học phí đầu tiên,
 *   không phải XFER-IN; không yêu cầu căn cứ hoa hồng > 0. Chưa có phiếu thì chưa chốt, chưa có thứ tự.
 * - Thứ tự chốt đếm lại từ 1 mỗi tháng theo ngày chốt rồi id khách, trên mọi chi nhánh của người nhận.
 *   Mặc định HS 1–5: 3%, 6–10: 4%, từ 11: 5% (cấu hình được). Mọi đợt đóng dùng bảng % tại ngày chốt.
 * - Phát sinh vào tháng phiếu được duyệt. Chỉ trả khi đủ 30 ngày từ ngày chốt VÀ đủ 3/3 mốc chăm sóc
 *   tháng đầu; chưa đạt thì hoãn sang kỳ sau, giữ % tại ngày chốt. Lịch chăm sóc vẫn theo chuyển đổi CRM.
 * - Người nhận = commission_user_id (người phụ trách lúc chuyển đổi CRM), khách cũ dùng assigned_user_id.
 * - Không thu hồi khi hoàn phí / hủy hóa đơn; hủy các khoản chưa trả liên quan, giữ khoản đã trả.
 *   CommissionAdjustment lịch sử / thủ công chưa quyết toán vẫn được áp dụng trong bảng lương.
 */
class SalesCommissionService
{
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
     * phụ trách), commission_percent / commission_tier_name (mốc hiệu lực tại ngày chốt) và commission_amount.
     * $customerScope: truy vấn CrmCustomer đã lọc sẵn (chi nhánh / phạm vi xem của báo cáo CRM).
     */
    public function commissionableReceipts(CarbonInterface $start, CarbonInterface $end, ?int $userId = null, ?Builder $customerScope = null): Collection
    {
        $this->rankCache = [];
        $customers = $this->convertedCustomers($userId, $customerScope)->keyBy('converted_student_id');
        if ($customers->isEmpty()) {
            return (new TuitionReceipt)->newCollection();
        }

        $tuitions = StudentTuition::whereIn('student_id', $customers->keys())
            ->orderBy('id')
            ->get(['id', 'student_id', 'final_amount', 'other_fees', 'created_at']);
        $tuitionsByStudent = $tuitions->groupBy('student_id');
        $tuitionById = $tuitions->keyBy('id');
        // Thứ tự tính trên mọi chi nhánh của các người nhận, kể cả khi tiền đang lọc theo phạm vi báo cáo.
        $owners = $customers->pluck('commission_owner_id')->unique();
        $rankCustomers = $this->convertedCustomers(null, CrmCustomer::query()->where(fn ($q) => $q
            ->whereIn('commission_user_id', $owners)
            ->orWhere(fn ($legacy) => $legacy->whereNull('commission_user_id')->whereIn('assigned_user_id', $owners))));
        $closeDates = $this->closeDates($rankCustomers);
        $this->cacheClosingRanks($rankCustomers, $closeDates);
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
            ->each(function (TuitionReceipt $receipt) use ($customers, $tuitionById, $closeDates, &$rates) {
                $customer = $customers->get($receipt->student_id);
                $rank = $this->closingRank($customer);
                $rates[$customer->id] ??= $this->rateForRank($rank, $closeDates[$customer->id]);
                $base = $this->tuitionBase($receipt, $tuitionById->get($receipt->student_tuition_id));

                $receipt->setAttribute('commission_owner_id', $customer->commission_owner_id);
                $receipt->setAttribute('commission_customer_id', $customer->id);
                $receipt->setAttribute('commission_base', $base);
                $receipt->setAttribute('closing_rank', $rank);
                $receipt->setAttribute('commission_percent', $rates[$customer->id]['percent']);
                $receipt->setAttribute('commission_tier_name', $rates[$customer->id]['tier_name']);
                $receipt->setAttribute('commission_amount', round($base * $rates[$customer->id]['percent'] / 100, 0));
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
     * Ngày chốt hoa hồng = phiếu dương được duyệt sớm nhất của khoản học phí đầu tiên, không gồm XFER-IN.
     * Không yêu cầu căn cứ hoa hồng > 0; dùng chung bộ lọc khoản học phí với commissionableReceipts().
     * Truy vấn theo lô, không truy vấn riêng từng học viên. Khách chưa đóng tiền không có trong kết quả.
     *
     * @param  Collection|array<CrmCustomer>  $customers
     * @return array<int, Carbon> [crm_customer_id => ngày chốt]
     */
    public function closeDates(Collection|array $customers): array
    {
        $customers = collect($customers);
        $studentIds = $customers->pluck('converted_student_id')->filter()->unique();
        if ($studentIds->isEmpty()) {
            return [];
        }

        $tuitions = StudentTuition::whereIn('student_id', $studentIds)->orderBy('id')
            ->get(['id', 'student_id', 'created_at']);
        $byStudent = $tuitions->groupBy('student_id');
        $byId = $tuitions->keyBy('id');
        $firstDates = TuitionReceipt::query()
            ->where('status', TuitionReceipt::STATUS_APPROVED)
            ->where('amount', '>', 0)
            ->whereNotNull('approved_at')
            ->where(fn ($q) => $q->whereNull('transaction_code')->orWhere('transaction_code', 'not like', 'XFER-IN-%'))
            ->where(fn ($q) => $q->whereIn('student_id', $studentIds)->orWhereIn('student_tuition_id', $byId->keys()))
            ->orderBy('approved_at')->orderBy('id')
            ->get(['id', 'student_id', 'student_tuition_id', 'approved_at', 'created_at'])
            ->each(fn (TuitionReceipt $receipt) => $receipt->student_id ??= $byId->get($receipt->student_tuition_id)?->student_id)
            ->filter(fn (TuitionReceipt $receipt) => $this->belongsToFirstTuition($receipt, $byStudent->get($receipt->student_id, collect())))
            ->groupBy('student_id')->map(fn (Collection $receipts) => $receipts->first()->approved_at);

        return $customers->filter(fn (CrmCustomer $customer) => $firstDates->has($customer->converted_student_id))
            ->mapWithKeys(fn (CrmCustomer $customer) => [$customer->id => $firstDates->get($customer->converted_student_id)])
            ->all();
    }

    /** Thứ tự chốt trong tháng theo ngày đóng học phí thành công rồi id khách; chưa chốt trả về null. */
    public function closingRank(CrmCustomer $customer): ?int
    {
        $owner = (int) ($customer->commission_user_id ?? $customer->assigned_user_id);
        if (! array_key_exists($owner, $this->rankCache)) {
            $this->rankCache[$owner] = [];
            $this->cacheClosingRanks($this->convertedCustomers($owner));
        }

        return $this->rankCache[$owner][$customer->id] ?? null;
    }

    /** Xếp hạng theo lô trên mọi chi nhánh, đếm lại từ 1 theo từng người nhận và tháng chốt. */
    private function cacheClosingRanks(Collection $customers, ?array $dates = null): void
    {
        $dates ??= $this->closeDates($customers);
        foreach ($customers->groupBy('commission_owner_id') as $owner => $mine) {
            $this->rankCache[$owner] = [];
            $months = $mine->filter(fn (CrmCustomer $customer) => isset($dates[$customer->id]))
                ->groupBy(fn (CrmCustomer $customer) => $dates[$customer->id]->format('Y-m'));
            foreach ($months as $month) {
                $ordered = $month->sortBy(fn (CrmCustomer $customer) => [$dates[$customer->id]->getTimestamp(), $customer->id])->values();
                foreach ($ordered as $i => $customer) {
                    $this->rankCache[$owner][$customer->id] = $i + 1;
                }
            }
        }
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
        $customers = CrmCustomer::query()
            ->whereNotNull('converted_student_id')
            ->where(fn ($q) => $q->where('commission_user_id', $userId)
                ->orWhere(fn ($legacy) => $legacy->whereNull('commission_user_id')->where('assigned_user_id', $userId)))
            ->get(['id', 'name', 'converted_student_id', 'commission_user_id', 'assigned_user_id'])
            ->keyBy('converted_student_id');
        $closeDates = $this->closeDates($customers);
        $closedHere = $customers->filter(fn (CrmCustomer $customer) => isset($closeDates[$customer->id]) && $closeDates[$customer->id]->between($start, $end));
        $closed = $closedHere->count();
        $studentIds = $allReceipts->filter(fn (Collection $receipts) => $receipts->contains($inMonth))->keys()
            ->merge($closedHere->keys())->unique()->values();
        $studentNames = Student::whereIn('id', $studentIds)->pluck('name', 'id');
        // Khoản học phí đầu tiên (khoản tính hoa hồng khách mới): học phí khóa không gồm sách / Thu khác.
        $firstTuitions = StudentTuition::whereIn('student_id', $studentIds)->orderBy('id')
            ->get(['id', 'student_id', 'final_amount', 'other_fees'])
            ->groupBy('student_id')->map(fn (Collection $tuitions) => $tuitions->first());

        $rows = $studentIds->map(function ($studentId) use ($allReceipts, $inMonth, $closedHere, $studentNames, $firstTuitions, $closeDates) {
            $all = $allReceipts->get($studentId, collect());
            $mine = $all->filter($inMonth);
            $first = $all->first();
            $customer = $closedHere->get($studentId);
            $rank = $first ? (int) $first->closing_rank : $this->closingRank($customer);
            $closedAt = $closeDates[$customer?->id ?? $first?->commission_customer_id] ?? null;
            $percent = $first ? (float) $first->commission_percent : $this->rateForRank($rank, $closedAt)['percent'];
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
     * Số HS chốt trong [start, end] theo ngày đóng học phí thành công đầu tiên (đúng phạm vi khách): [user_id => số].
     */
    public function closedCountsBySales(CarbonInterface $start, CarbonInterface $end, ?Builder $customerScope = null): Collection
    {
        $customers = ($customerScope ? (clone $customerScope) : CrmCustomer::query())
            ->whereNotNull('converted_student_id')
            ->get(['id', 'converted_student_id', 'commission_user_id', 'assigned_user_id']);
        $dates = $this->closeDates($customers);

        return $customers
            ->filter(fn (CrmCustomer $customer) => isset($dates[$customer->id]) && $dates[$customer->id]->between($start->copy()->startOfDay(), $end->copy()->endOfDay()))
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
     * % theo mốc chứa thứ tự chốt của học viên trong tháng chốt (bảng mốc hiệu lực tại ngày đóng học phí thành công đầu tiên).
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
            ->orderBy('id')->get(['id', 'converted_student_id'])
            ->keyBy('converted_student_id');
        $closeDates = $this->closeDates($customers);
        $missingStudentIds = $customers->reject(fn (CrmCustomer $customer) => isset($closeDates[$customer->id]))->keys();
        $closingDates = $missingStudentIds->isNotEmpty()
            ? app(FirstMonthCareService::class)->closingDates(Student::whereIn('id', $missingStudentIds)->get(['id', 'created_at']))
            : [];
        $existing = CommissionItem::with('payrollRecord.period')->whereIn('tuition_receipt_id', $receipts->modelKeys())->get()->keyBy('tuition_receipt_id');

        foreach ($receipts as $receipt) {
            $item = $existing->get($receipt->id);
            // Khoản đã trả không sửa; khoản đã hủy (hủy hóa đơn / hoàn phí) không mở lại.
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
                'closed_at' => $closeDates[$customer?->id] ?? ($closingDates[(int) $receipt->student_id] ?? null),
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

    /** Hoàn phí không thu hồi khoản đã trả; hủy khoản chưa trả của người nhận hoa hồng. Chuyển nhượng giữ nguyên sổ. */
    public function recordRefundApproval(TuitionRefundRequest $refund): int
    {
        $refund->forceFill([
            'clawback_commission' => false,
            'clawback_amount' => 0,
            'clawback_user_id' => null,
            'approved_at' => now(),
        ])->save();
        $owner = $this->ownerOfStudent((int) $refund->student_id);
        if ($refund->type !== 'refund' || ! $owner) {
            return 0;
        }

        $items = CommissionItem::open()->with('payrollRecord.period')
            ->where('student_id', $refund->student_id)->where('user_id', $owner)
            ->get()->reject(fn (CommissionItem $item) => $item->isPaidOut());
        $items->each(fn (CommissionItem $item) => $item->update([
            'status' => CommissionItem::STATUS_VOID, 'payroll_record_id' => null,
            'deferred_reason' => "Không trả do học viên hoàn phí #{$refund->id}",
        ]));

        return $items->count();
    }

    /** Hủy khoản chưa trả của phiếu bị hủy hóa đơn; khoản đã trả giữ nguyên, không tạo điều chỉnh thu hồi. */
    public function voidForCancelledReceipt(TuitionReceipt $receipt, string $invoiceNumber): void
    {
        $item = CommissionItem::with('payrollRecord.period')->where('tuition_receipt_id', $receipt->id)->first();
        if (! $item || $item->status === CommissionItem::STATUS_VOID || $item->isPaidOut()) {
            return;
        }

        $item->update([
            'status' => CommissionItem::STATUS_VOID, 'payroll_record_id' => null,
            'deferred_reason' => "Không trả do hủy hóa đơn {$invoiceNumber}",
        ]);
    }

    /**
     * Khách CRM đã chuyển đổi, kèm commission_owner_id = người nhận hoa hồng; giữ từng khách để xếp thứ tự chốt.
     */
    private function convertedCustomers(?int $userId, ?Builder $customerScope = null): Collection
    {
        return ($customerScope ? (clone $customerScope) : CrmCustomer::query())
            ->whereNotNull('converted_student_id')
            ->when($userId, fn ($q) => $q->where(fn ($owner) => $owner->where('commission_user_id', $userId)
                ->orWhere(fn ($legacy) => $legacy->whereNull('commission_user_id')->where('assigned_user_id', $userId))))
            ->orderBy('id')
            ->get(['id', 'converted_student_id', 'commission_user_id', 'assigned_user_id'])
            ->each(fn (CrmCustomer $customer) => $customer->setAttribute('commission_owner_id', $customer->commission_user_id ?? $customer->assigned_user_id))
            ->filter(fn (CrmCustomer $customer) => $customer->commission_owner_id);
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
