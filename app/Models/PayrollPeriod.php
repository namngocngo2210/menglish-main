<?php

namespace App\Models;

use App\Services\PayrollFormulaService;
use App\Services\SalesCommissionService;
use App\Services\StaffAttendance\StaffAttendanceService;
use App\Support\Money;
use App\Support\RequestMemo;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PayrollPeriod extends Model
{
    use HasFactory;

    /** Kỳ ở các trạng thái này bị khoá hoàn toàn (không tính lại, không ghi thêm dữ liệu). */
    public const LOCKED_STATUSES = ['approved', 'paid'];

    protected $table = 'payroll_periods';

    protected $fillable = [
        'code',
        'title',
        'month',
        'year',
        'start_date',
        'end_date',
        'status',
        'total_staff',
        'total_hours',
        'total_amount',
        'calculated_at',
    ];

    protected $casts = [
        'month' => 'integer',
        'year' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'total_staff' => 'integer',
        'total_hours' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'calculated_at' => 'datetime',
    ];

    public function records(): HasMany
    {
        return $this->hasMany(PayrollRecord::class, 'payroll_period_id');
    }

    public function isLocked(): bool
    {
        return in_array($this->status, self::LOCKED_STATUSES, true);
    }

    protected static function booted(): void
    {
        static::saved(fn () => RequestMemo::forget('payroll_periods.'));
        static::deleted(fn () => RequestMemo::forget('payroll_periods.'));
    }

    /**
     * Ngày này có thuộc một kỳ lương đã duyệt/đã chi trả không. Dữ liệu chấm công
     * hay phạt ghi vào ngày đó sẽ không bao giờ được trả/trừ (kỳ sau chỉ quét
     * dữ liệu trong khoảng ngày của chính nó) nên phải bị từ chối.
     */
    public static function isLockedFor(CarbonInterface|string $date): bool
    {
        $day = Carbon::parse($date)->toDateString();

        // Khoảng ngày của các kỳ đã khoá nạp 1 lần / request (màn chấm công kiểm tra từng dòng).
        $ranges = RequestMemo::remember('payroll_periods.locked', fn () => static::query()
            ->whereIn('status', self::LOCKED_STATUSES)
            ->get(['start_date', 'end_date'])
            ->map(fn (self $period) => [Carbon::parse($period->start_date)->toDateString(), Carbon::parse($period->end_date)->toDateString()]));

        return $ranges->contains(fn (array $range) => $range[0] <= $day && $range[1] >= $day);
    }

    /**
     * Lịch chốt lương (chủ dự án chốt):
     * - chốt KPI: ngày cuối tháng của kỳ;
     * - chốt công + chốt lỗi: hết ngày cuối tháng + 2 ngày → sau mốc này mới duyệt (chốt) bảng lương;
     * - trả lương: ngày 10–15 tháng sau.
     * Chỉ là mốc lịch — không thay thế khóa theo trạng thái (isLockedFor) và không chặn "Đồng bộ & Tính lại".
     */
    public const CLOSE_AFTER_DAYS = 2;

    public const PAY_DAY_FROM = 10;

    public const PAY_DAY_TO = 15;

    public function kpiCloseOn(): Carbon
    {
        return Carbon::parse($this->end_date)->startOfDay();
    }

    public function attendanceCloseAt(): Carbon
    {
        return Carbon::parse($this->end_date)->addDays(self::CLOSE_AFTER_DAYS)->endOfDay();
    }

    public function violationCloseAt(): Carbon
    {
        return $this->attendanceCloseAt();
    }

    /** Được duyệt bảng lương khi đã qua mốc chốt công / chốt lỗi. */
    public function canApproveAt(?CarbonInterface $now = null): bool
    {
        $now ??= now();

        return $now->gt($this->attendanceCloseAt()) && $now->gt($this->violationCloseAt());
    }

    /** @return array{0: Carbon, 1: Carbon} [đầu, cuối] khung trả lương ở tháng sau */
    public function payWindow(): array
    {
        $nextMonth = Carbon::parse($this->end_date)->startOfMonth()->addMonthNoOverflow();

        return [$nextMonth->copy()->day(self::PAY_DAY_FROM)->startOfDay(), $nextMonth->copy()->day(self::PAY_DAY_TO)->endOfDay()];
    }

    public function isInPayWindow(?CarbonInterface $at = null): bool
    {
        [$from, $to] = $this->payWindow();

        return ($at ?? now())->between($from, $to);
    }

    /** Mốc lịch gửi cho màn bảng lương. */
    public function calendar(): array
    {
        [$payFrom, $payTo] = $this->payWindow();

        return [
            'kpi_close_on' => $this->kpiCloseOn()->format('d/m/Y'),
            'attendance_close_on' => $this->attendanceCloseAt()->format('d/m/Y'),
            'violation_close_on' => $this->violationCloseAt()->format('d/m/Y'),
            'approve_from' => $this->attendanceCloseAt()->addDay()->format('d/m/Y'),
            'pay_from' => $payFrom->format('d/m/Y'),
            'pay_to' => $payTo->format('d/m/Y'),
            'can_approve' => $this->canApproveAt(),
            'in_pay_window' => $this->isInPayWindow(),
        ];
    }

    public static function lockedMessage(CarbonInterface|string $date): string
    {
        return 'Ngày '.Carbon::parse($date)->format('d/m/Y').' thuộc kỳ lương đã duyệt/đã chi trả — không thể ghi nhận hoặc thay đổi dữ liệu lương cho ngày này.';
    }

    /**
     * Chấm công/phạt trong kỳ bị thêm/sửa sau lần tính gần nhất → phải tính lại trước khi duyệt.
     */
    public function hasChangesSinceCalculation(): bool
    {
        if ($this->calculated_at === null) {
            return true;
        }

        $range = [$this->start_date, $this->end_date];

        return TeacherTimesheet::whereBetween('teaching_date', $range)->where('updated_at', '>', $this->calculated_at)->exists()
            // Chỉ biên bản đã quyết phạt mới ảnh hưởng lương (ghi nhận / giải trình / khắc phục không bắt tính lại)
            || Penalty::whereBetween('violation_date', $range)->whereIn('status', Penalty::payableStatuses())->where('updated_at', '>', $this->calculated_at)->exists()
            // Đơn giá dạy, cấu hình lương (BHXH, công đoàn, tái tục...) và tiêu chí KPI đổi sau lần tính
            || TeacherHourlyRate::whereBetween('updated_at', [$this->calculated_at, now()])->where('updated_at', '>', $this->calculated_at)->exists()
            || TeacherRate::whereBetween('updated_at', [$this->calculated_at, now()])->where('updated_at', '>', $this->calculated_at)->exists()
            || SystemSetting::where('key', 'like', 'payroll_%')->whereBetween('updated_at', [$this->calculated_at, now()])->where('updated_at', '>', $this->calculated_at)->exists()
            || KpiCriterion::whereBetween('updated_at', [$this->calculated_at, now()])->where('updated_at', '>', $this->calculated_at)->exists()
            // Biên bản đổi trạng thái sau lần tính (quyết phạt/nộp) hoặc vừa quá hạn nộp mà chưa được trừ
            || Penalty::whereIn('payroll_record_id', $this->records()->select('id'))->where('updated_at', '>', $this->calculated_at)->exists()
            || Penalty::deductibleFor($this)->whereNull('payroll_record_id')
                ->whereIn('user_id', $this->records()->select('user_id'))->exists()
            // Biên bản đã trừ ở lần tính bị gỡ khỏi phiếu (nộp trực tiếp / miễn phạt) — kể cả biên bản vi phạm từ kỳ trước
            || $this->penaltyDeductionOutOfSync()
            // Phiếu thu được duyệt / thu hồi hoa hồng phát sinh sau lần tính
            || TuitionReceipt::whereBetween('approved_at', [$this->start_date->copy()->startOfDay(), $this->end_date->copy()->endOfDay()])
                ->where('updated_at', '>', $this->calculated_at)->exists()
            || CommissionAdjustment::whereNull('settled_at')->whereNull('payroll_record_id')
                ->where('created_at', '<=', $this->end_date->copy()->endOfDay())
                ->where('created_at', '>', $this->calculated_at)->exists()
            // Khoản hoa hồng bị hủy / đổi sau lần tính (hủy hóa đơn, hoàn phí có thu hồi)
            || CommissionItem::whereNull('settled_at')->where('updated_at', '>', $this->calculated_at)
                ->whereDate('earned_period_end', '<=', $this->end_date->toDateString())->exists()
            // Khoản hoa hồng đang hoãn có thể vừa đạt gate (CM tick mốc chăm sóc / hoàn thành việc chăm sóc)
            || CommissionItem::open()->whereNull('payroll_record_id')
                ->where(fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('updated_at', '>', $this->calculated_at))
                    ->orWhereIn('student_id', WorkTask::whereNotNull('care_milestone')->where('updated_at', '>', $this->calculated_at)->select('student_id')))
                ->exists()
            // Chấm công hằng ngày / đơn nghỉ đổi sau lần tính (ngày công, đi muộn hiện trên phiếu lương)
            || StaffAttendance::whereBetween('work_date', $range)->where('updated_at', '>', $this->calculated_at)->exists()
            || StaffAttendanceRequest::approved()->overlapping($this->start_date->toDateString(), $this->end_date->toDateString())
                ->where('updated_at', '>', $this->calculated_at)->exists()
            // Đánh giá KPI tháng của kỳ được chốt / sửa sau lần tính (KPI Học vụ tự động)
            || KpiEvaluation::where('month', $this->month)->where('year', $this->year)
                ->where('updated_at', '>', $this->calculated_at)->exists();
    }

    /** Tiền phạt đã trừ trên phiếu khác tổng các biên bản còn gắn với phiếu đó. */
    private function penaltyDeductionOutOfSync(): bool
    {
        $linked = Penalty::whereIn('payroll_record_id', $this->records()->select('id'))
            ->selectRaw('payroll_record_id, SUM(amount) as total')->groupBy('payroll_record_id')
            ->pluck('total', 'payroll_record_id');

        return $this->records()->get(['id', 'penalty_deduction'])
            ->contains(fn (PayrollRecord $record) => abs((float) $record->penalty_deduction - (float) ($linked[$record->id] ?? 0)) > 0.5);
    }

    /**
     * Tổng hợp lại số liệu kỳ từ các bản ghi lương hiện có.
     */
    public function refreshTotals(): void
    {
        $totals = $this->records()
            ->selectRaw('COUNT(*) as staff, COALESCE(SUM(actual_hours), 0) as hours, COALESCE(SUM(net_salary), 0) as amount')
            ->toBase()
            ->first();

        $this->update([
            'total_staff' => (int) $totals->staff,
            'total_hours' => (float) $totals->hours,
            'total_amount' => (float) $totals->amount,
        ]);
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            'draft' => 'bg-warning/10 text-warning border-warning/30',
            'reviewing' => 'bg-secondary/10 text-secondary border-secondary/30',
            'approved' => 'bg-tertiary/10 text-tertiary border-tertiary/30',
            'paid' => 'bg-accent-container text-accent border-accent/30',
            default => 'bg-surface-container-low text-on-surface-variant border-surface-container-highest',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'draft' => 'Đang tính',
            'reviewing' => 'Đang soát',
            'approved' => 'Đã duyệt',
            'paid' => 'Đã trả',
            default => $this->status,
        };
    }

    /**
     * Tham số nghiệp vụ lương: SystemSetting (tiền tố payroll_) ghi đè config/payroll.php.
     */
    public static function payrollSettings(): array
    {
        $renewal = SystemSetting::get('payroll_renewal_table');
        $table = [];
        foreach ((is_array($renewal) ? $renewal : config('payroll.renewal_bonus.table', [])) as $quits => $row) {
            $table[(int) $quits] = [
                'percent' => (float) (is_array($row) ? ($row['percent'] ?? 0) : $row),
                'pending' => (bool) (is_array($row) ? ($row['pending'] ?? false) : false),
            ];
        }
        ksort($table);

        return [
            'insurance_rate_percent' => (float) SystemSetting::get('payroll_insurance_rate_percent', config('payroll.insurance_rate_percent', 10.5)),
            'union_rate_percent' => (float) SystemSetting::get('payroll_union_rate_percent', config('payroll.union_rate_percent', 0.5)),
            'academic_kpi_fund' => (float) SystemSetting::get('payroll_academic_kpi_fund', config('payroll.academic_kpi_fund', 2000000)),
            'retention_tiers' => array_map('floatval', (array) config('payroll.retention_tiers', [15000, 20000, 25000])),
            'renewal_table' => $table,
            // Đi muộn / về sớm của GV: ngưỡng phút, đơn giá trừ mỗi phút khi không báo trước (4.000–5.000đ).
            'late_threshold_minutes' => (int) SystemSetting::get('payroll_late_threshold_minutes', config('payroll.late.threshold_minutes', 15)),
            'late_deduction_per_minute' => (float) SystemSetting::get('payroll_late_deduction_per_minute', config('payroll.late.deduction_per_minute', 5000)),
            'renewal_beyond_percent' => (float) SystemSetting::get('payroll_renewal_beyond_percent', config('payroll.renewal_bonus.beyond_percent', 0)),
        ];
    }

    /**
     * Tự động tính bảng lương theo công thức Q3 (A6 25/09/2026) cho mọi nhân sự đang hoạt động:
     * chấm công (buổi dạy), KPI, hoa hồng (gate kép), thưởng tái tục, phạt quá hạn, BHXH/Công đoàn;
     * giữ nguyên các khoản nhập tay của phiếu lương. Xem PayrollRecord về hai công thức Part-time / Full-time.
     */
    public function calculatePayrollForPeriod(): void
    {
        // Khoá theo kỳ để 2 lần bấm "Tính lại" song song không ghi đè lẫn nhau;
        // transaction đảm bảo bản ghi lương, liên kết phạt và tổng kỳ nhất quán.
        Cache::lock("payroll:period:{$this->id}", 120)->block(10, function () {
            DB::transaction(fn () => $this->runCalculation());
        });
    }

    private function runCalculation(): void
    {
        $settings = self::payrollSettings();
        // Nhân sự đang hoạt động + người đã nghỉ / bị xóa (xóa mềm) nhưng còn công dạy hợp lệ trong kỳ,
        // để tính lại không làm mất lương những buổi họ đã dạy.
        $departedIds = TeacherTimesheet::whereBetween('teaching_date', [$this->start_date, $this->end_date])
            ->where('status', 'valid')->distinct()->pluck('user_id');
        $users = User::withTrashed()->with('roles')
            ->where(fn ($query) => $query->where(fn ($active) => $active->where('is_active', true)->whereNull('deleted_at'))
                ->orWhereIn('id', $departedIds))
            ->get();
        $producedUserIds = [];
        $commissionService = app(SalesCommissionService::class);
        $formula = app(PayrollFormulaService::class);
        $attendanceService = app(StaffAttendanceService::class);
        $existingRecords = $this->records()->get()->keyBy('user_id');
        $start = $this->start_date->copy();
        $end = $this->end_date->copy();

        // Gỡ liên kết phạt / điều chỉnh / khoản hoa hồng của lần tính trước; lần tính này gắn lại đúng khoản.
        Penalty::whereIn('payroll_record_id', $this->records()->select('id'))
            ->toBase()
            ->update(['payroll_record_id' => null]);
        CommissionAdjustment::whereIn('payroll_record_id', $this->records()->select('id'))
            ->whereNull('settled_at')
            ->toBase()
            ->update(['payroll_record_id' => null]);
        CommissionItem::whereIn('payroll_record_id', $this->records()->select('id'))
            ->whereNull('settled_at')
            ->toBase()
            ->update(['payroll_record_id' => null, 'status' => CommissionItem::STATUS_DEFERRED]);

        // Hoa hồng: ghi sổ khoản phát sinh trong kỳ (% theo mốc thứ tự chốt của HS), rồi xét gate kép cho mọi khoản chưa trả.
        $commissionService->syncItemsForPeriod($this);
        $commissionByUser = $commissionService->resolveForPeriod($this);

        foreach ($users as $user) {
            $existing = $existingRecords->get($user->id);
            $profile = $formula->profile($user);
            $isPartTime = $profile['employee_type'] === PayrollRecord::TYPE_PARTTIME;

            // 1. Chấm công hợp lệ trong kỳ: mỗi ca = 1 buổi dạy
            $timesheets = TeacherTimesheet::where('user_id', $user->id)
                ->whereBetween('teaching_date', [$start, $end])
                ->where('status', 'valid')
                ->with('classModel')
                ->get();
            $outcomes = $timesheets->values()->map(fn (TeacherTimesheet $ts) => $ts->payOutcome($user, $settings));
            // Ca đi muộn / về sớm từ ngưỡng mà không báo trước: không tính buổi (chỉ áp cho Part-time; Full-time lương cơ bản không đổi).
            $voided = $isPartTime ? $outcomes->where('counted', false)->count() : 0;
            $actualHours = (float) $timesheets->values()->filter(fn (TeacherTimesheet $ts, $i) => ! $isPartTime || $outcomes[$i]['counted'])->sum('hours');
            $sessions = $timesheets->count() - $voided;
            $sessionPay = $outcomes;
            $teachingSalary = $isPartTime ? round((float) $sessionPay->sum('amount'), 2) : 0.0;
            // Chi tiết khoản trừ đi muộn / về sớm để Kế toán thấy lý do trên phiếu lương.
            $lateLines = $isPartTime ? $timesheets->values()->map(fn (TeacherTimesheet $ts, $i) => $outcomes[$i]['late_rule'] ? [
                'timesheet_id' => $ts->id,
                'date' => $ts->teaching_date?->format('d/m/Y'),
                'class' => $ts->classModel?->name,
                'minutes' => $outcomes[$i]['late_minutes'],
                'rule' => $outcomes[$i]['late_rule'],
                'deduction' => $outcomes[$i]['late_deduction'],
            ] : null)->filter()->values()->all() : [];

            // Buổi có GVNN cùng lớp (chỉ để Kế toán tham khảo khi nhập dòng "Buổi có GVNN" — chờ BA chốt cách tính)
            $foreignSessions = $timesheets->filter(function (TeacherTimesheet $ts) use ($user) {
                $foreignTeacherId = $ts->classModel?->foreign_teacher_id;

                return $foreignTeacherId && (int) $foreignTeacherId !== (int) $user->id
                    && TeacherTimesheet::where('class_id', $ts->class_id)->whereDate('teaching_date', $ts->teaching_date)
                        ->where('user_id', $foreignTeacherId)->where('status', 'valid')->exists();
            })->count();

            // 2. Hoa hồng: khoản đạt gate kép trong kỳ (kể cả khoản hoãn từ kỳ trước); còn lại hoãn.
            $commission = $commissionByUser->get($user->id, ['payable' => collect(), 'deferred' => collect()]);
            $commissionBonus = (float) $commission['payable']->sum('amount');
            $commissionBase = (float) $commission['payable']->sum('base_amount');
            $commissionDeferred = (float) $commission['deferred']->sum('amount');
            $closedCount = $commissionService->closedCountFor($user->id, $start, $end);
            // Mốc hiện tại (% của HS chốt gần nhất trong kỳ); từng khoản mang % theo thứ tự chốt của học viên.
            $currentPercent = $closedCount > 0 ? $commissionService->milestoneFor($closedCount, $end)['percent'] : null;

            $adjustments = CommissionAdjustment::where('user_id', $user->id)
                ->whereNull('settled_at')
                ->whereNull('payroll_record_id')
                ->where('created_at', '<=', $end->copy()->endOfDay())
                ->get(['id', 'amount']);
            $commissionClawback = (float) max(0, -$adjustments->sum('amount'));

            // 3. Phạt: chỉ biên bản đã quyết phạt mà QUÁ HẠN NỘP chưa nộp (Penalty::scopeDeductibleFor).
            $penalties = Penalty::where('user_id', $user->id)->deductibleFor($this)->get(['id', 'amount']);
            $penaltyDeduction = (float) $penalties->sum('amount');

            // 4. Công thức theo loại nhân sự
            $baseSalary = $isPartTime ? 0.0 : (float) max(0, (float) $user->base_salary);
            $details = [
                'rates' => ['insurance' => $settings['insurance_rate_percent'], 'union' => $settings['union_rate_percent']],
                'sessions' => ['count' => $sessions, 'per_session' => $sessionPay->where('unit', 'session')->count(), 'per_hour' => $sessionPay->where('unit', 'hour')->count()],
                'late' => ['threshold' => $settings['late_threshold_minutes'], 'per_minute' => $settings['late_deduction_per_minute'], 'total_deduction' => round((float) collect($lateLines)->sum('deduction'), 2), 'lines' => $lateLines],
            ];
            $kpiSource = null;
            $kpiScore = null;
            $kpiAuto = 0.0;
            $retention = ['base' => 0, 'retained' => 0];
            $renewal = ['amount' => 0.0, 'classes' => []];

            if ($isPartTime) {
                $kpiSource = PayrollRecord::KPI_RETENTION;
                $retentionData = $formula->retentionFor(
                    $formula->retentionClassIds($user, $timesheets->pluck('class_id')->unique()->all()), $start, $end
                );
                $retention = ['base' => $retentionData['base'], 'retained' => $retentionData['retained']];
                $details['retention'] = ['base' => $retentionData['base'], 'lost' => $retentionData['lost'], 'retained' => $retentionData['retained'], 'lost_ids' => $retentionData['lost_ids']];
            } else {
                if ($profile['salary_role'] === 'academic_staff') {
                    $kpiSource = PayrollRecord::KPI_ACADEMIC;
                    $academic = $formula->academicKpiFor($user, (int) $this->month, (int) $this->year);
                    $kpiAuto = $academic['amount'];
                    $kpiScore = $academic['score'];
                    $details['kpi'] = ['fund' => $academic['fund'], 'score' => $academic['score'], 'evaluation_id' => $academic['evaluation_id'], 'items' => $academic['items']];
                } else {
                    $kpiSource = PayrollRecord::KPI_MANUAL;
                }
                $renewal = $formula->renewalBonusFor($user, $start, $end);
                $details['renewal'] = $renewal;
            }
            // Chấm công hằng ngày (điện thoại): ngày công, đi muộn, nghỉ có phép — để Kế toán đối soát; tiền phạt đi muộn
            // đi qua biên bản (chờ giải trình → người chốt quyết mức phạt) như mọi biên bản khác.
            $attendanceSummary = $attendanceService->summary($user->id, $start, $end);
            if ($attendanceSummary['days'] > 0 || $attendanceSummary['leave_days'] > 0) {
                $details['attendance'] = $attendanceSummary;
            }
            $details['commission'] = [
                'closed_count' => $closedCount,
                'deferred' => $commission['deferred']->map(fn (CommissionItem $i) => ['id' => $i->id, 'amount' => (float) $i->amount, 'reason' => $i->deferred_reason])->values()->all(),
            ];

            $insuranceDeduction = round($baseSalary * $settings['insurance_rate_percent'] / 100, 0);
            $unionDeduction = round($baseSalary * $settings['union_rate_percent'] / 100, 0);

            // Khoản nhập tay của phiếu (giữ qua các lần tính lại)
            $manualLines = $existing?->manual_lines ?? [];
            $hasManualInput = ! empty($manualLines) || (float) ($existing?->foreign_session_pay ?? 0) > 0
                || (float) ($existing?->tax_deduction ?? 0) > 0 || $existing?->kpi_manual_amount !== null
                || ($existing?->retention_tier !== null && $retention['retained'] > 0);

            // Bỏ qua nhân sự không có khoản nào phát sinh trong kỳ (phạt quá hạn một mình không tạo phiếu — chờ kỳ có lương)
            if ($baseSalary == 0 && $sessions == 0 && $commissionBonus == 0 && $commissionClawback == 0 && $commissionDeferred == 0
                && $kpiAuto == 0 && $renewal['amount'] == 0 && ! $hasManualInput) {
                continue;
            }

            $record = $existing ?? new PayrollRecord(['payroll_period_id' => $this->id, 'user_id' => $user->id]);
            $record->fill([
                'department' => $profile['department'],
                'employee_type' => $profile['employee_type'],
                'salary_role' => $profile['salary_role'],
                'base_salary' => $baseSalary,
                'standard_hours' => 0,
                'actual_hours' => $actualHours,
                'teaching_sessions' => $sessions,
                'teaching_salary' => $teachingSalary,
                'retention_base_students' => $retention['base'],
                'retention_students' => $retention['retained'],
                'kpi_source' => $kpiSource,
                'kpi_score' => $kpiScore,
                'kpi_bonus' => $kpiAuto,
                'renew_bonus' => $renewal['amount'],
                'commission_bonus' => $commissionBonus,
                'commission_base' => $commissionBase,
                'commission_clawback' => $commissionClawback,
                'commission_deferred' => $commissionDeferred,
                'commission_percent' => $currentPercent,
                'commission_closed_count' => $closedCount,
                'penalty_deduction' => $penaltyDeduction,
                'foreign_teacher_sessions_count' => $foreignSessions,
                'foreign_teacher_deduction_rate' => 0,
                'foreign_teacher_deduction' => 0,
                'insurance_deduction' => $insuranceDeduction,
                'union_deduction' => $unionDeduction,
                'allowance_override' => null,
                'other_bonus' => 0,
                'calculation_details' => $details,
            ]);
            // KPI giữ HS (số HS × bậc tay), KPI tự do, phụ cấp / khấu trừ tự do → thực lĩnh
            $record->applyManualInputs();
            $record->notes = $this->autoNote($record);
            $record->save();

            if ($penalties->isNotEmpty()) {
                Penalty::whereKey($penalties->modelKeys())->toBase()->update(['payroll_record_id' => $record->id]);
            }
            if ($adjustments->isNotEmpty()) {
                CommissionAdjustment::whereKey($adjustments->modelKeys())->toBase()->update(['payroll_record_id' => $record->id]);
            }
            if ($commission['payable']->isNotEmpty()) {
                CommissionItem::whereKey($commission['payable']->pluck('id')->all())->toBase()
                    ->update(['payroll_record_id' => $record->id, 'status' => CommissionItem::STATUS_PAYABLE, 'deferred_reason' => null]);
            }
            foreach ($commission['deferred'] as $item) {
                CommissionItem::whereKey($item->id)->toBase()->update(['deferred_reason' => $item->deferred_reason]);
            }

            $producedUserIds[] = $user->id;
        }

        // Nhân sự không còn đủ điều kiện ở lần tính này không được giữ bản ghi cũ.
        $this->records()->whereNotIn('user_id', $producedUserIds)->delete();

        $this->status = 'reviewing';
        $this->calculated_at = now();
        $this->refreshTotals();
    }

    private function autoNote(PayrollRecord $record): string
    {
        $money = fn ($v) => Money::format((float) $v);
        $parts = [$record->employee_type_label.' ('.$record->salary_role_label.')'];
        if ($record->isPartTime()) {
            $parts[] = (int) $record->teaching_sessions.' buổi dạy = '.$money($record->teaching_salary);
            $parts[] = 'giữ '.(int) $record->retention_students.'/'.(int) $record->retention_base_students.' HS';
        } else {
            $parts[] = 'lương cơ bản '.$money($record->base_salary);
        }
        if ((float) $record->commission_bonus > 0 || (float) $record->commission_deferred > 0) {
            $parts[] = 'hoa hồng '.$money($record->commission_bonus).((float) $record->commission_deferred > 0 ? ' (hoãn '.$money($record->commission_deferred).')' : '');
        }
        if ((float) $record->renew_bonus > 0) {
            $parts[] = 'tái tục '.$money($record->renew_bonus);
        }
        if ((float) $record->penalty_deduction > 0) {
            $parts[] = 'phạt '.$money($record->penalty_deduction);
        }
        if ((float) $record->commission_clawback > 0) {
            $parts[] = 'thu hồi HH '.$money($record->commission_clawback);
        }

        return 'Tự động tính: '.implode(' · ', $parts);
    }
}
