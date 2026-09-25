<?php

namespace App\Models;

use App\Services\SalesCommissionService;
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

    /**
     * Ngày này có thuộc một kỳ lương đã duyệt/đã chi trả không. Dữ liệu chấm công
     * hay phạt ghi vào ngày đó sẽ không bao giờ được trả/trừ (kỳ sau chỉ quét
     * dữ liệu trong khoảng ngày của chính nó) nên phải bị từ chối.
     */
    public static function isLockedFor(CarbonInterface|string $date): bool
    {
        $day = Carbon::parse($date)->toDateString();

        return static::query()
            ->whereIn('status', self::LOCKED_STATUSES)
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day)
            ->exists();
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
            || Penalty::whereBetween('violation_date', $range)->where('updated_at', '>', $this->calculated_at)->exists()
            // Biên bản đổi trạng thái sau lần tính (quyết phạt/nộp) hoặc vừa quá hạn nộp mà chưa được trừ
            || Penalty::whereIn('payroll_record_id', $this->records()->select('id'))->where('updated_at', '>', $this->calculated_at)->exists()
            || Penalty::deductibleFor($this)->whereNull('payroll_record_id')
                ->whereIn('user_id', $this->records()->select('user_id'))->exists()
            // Phiếu thu được duyệt / thu hồi hoa hồng phát sinh sau lần tính
            || TuitionReceipt::whereBetween('approved_at', [$this->start_date->copy()->startOfDay(), $this->end_date->copy()->endOfDay()])
                ->where('updated_at', '>', $this->calculated_at)->exists()
            || CommissionAdjustment::whereNull('settled_at')->whereNull('payroll_record_id')
                ->where('created_at', '<=', $this->end_date->copy()->endOfDay())
                ->where('created_at', '>', $this->calculated_at)->exists();
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
            'draft' => 'bg-amber-50 text-amber-700 border-amber-200',
            'reviewing' => 'bg-blue-50 text-blue-700 border-blue-200',
            'approved' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'paid' => 'bg-purple-50 text-purple-700 border-purple-200',
            default => 'bg-gray-50 text-gray-700 border-gray-200',
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
     * Tham số nghiệp vụ lương đọc từ SystemSetting (tiền tố payroll_), fallback
     * về giá trị mặc định khi chưa cấu hình — trước đây bị hardcode trong phép tính.
     */
    public static function payrollSettings(): array
    {
        return [
            'allowance_amount' => (float) SystemSetting::get('payroll_allowance_amount', 500000),
            'kpi_bonus_amount' => (float) SystemSetting::get('payroll_kpi_bonus_amount', 1000000),
            'kpi_bonus_hours_threshold' => (float) SystemSetting::get('payroll_kpi_bonus_hours_threshold', 40),
            'insurance_rate_percent' => (float) SystemSetting::get('payroll_insurance_rate_percent', 10.5),
            'foreign_teacher_deduction_rate' => (float) SystemSetting::get('payroll_foreign_teacher_deduction_rate', 50000),
        ];
    }

    /**
     * Tự động tính toán bảng lương cho tất cả nhân viên dựa trên:
     * Cấu hình lương nhân viên + Dữ liệu chấm công ca dạy + Thưởng KPI/Hoa hồng CRM + Giảm trừ biên bản phạt.
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
        $users = User::where('is_active', true)->get();
        $producedUserIds = [];
        $commissionService = app(SalesCommissionService::class);
        $existingRecords = $this->records()->get()->keyBy('user_id');

        // Gỡ liên kết phạt / điều chỉnh hoa hồng của lần tính trước; lần tính này gắn lại đúng khoản đã trừ.
        Penalty::whereIn('payroll_record_id', $this->records()->select('id'))
            ->toBase()
            ->update(['payroll_record_id' => null]);
        CommissionAdjustment::whereIn('payroll_record_id', $this->records()->select('id'))
            ->whereNull('settled_at')
            ->toBase()
            ->update(['payroll_record_id' => null]);

        // Doanh thu thực thu làm căn cứ hoa hồng của cả kỳ (xem SalesCommissionService).
        $collectedBySales = $commissionService->collectedBySales($this->start_date, $this->end_date);

        foreach ($users as $user) {
            $existing = $existingRecords->get($user->id);

            // 1. Giờ dạy thực tế từ bảng chấm công
            $timesheets = TeacherTimesheet::where('user_id', $user->id)
                ->whereBetween('teaching_date', [$this->start_date, $this->end_date])
                ->where('status', 'valid')
                ->with('classModel')
                ->get();

            $actualHours = (float) $timesheets->sum('hours');
            $teachingSalary = (float) $timesheets->sum(
                fn (TeacherTimesheet $ts) => (float) $ts->hours * $ts->effectiveHourlyRate($user)
            );

            // 2. Hoa hồng tuyển sinh: % theo bậc hiệu lực tại cuối kỳ × tiền thực thu của khách mới
            //    (phiếu duyệt trong kỳ). Thu hồi hoa hồng do hoàn phí trừ riêng ở mục khấu trừ.
            $commissionBase = (float) ($collectedBySales->get($user->id) ?? 0);
            $commissionBonus = $commissionService->commissionFor($commissionBase, $this->end_date)['amount'];

            $adjustments = CommissionAdjustment::where('user_id', $user->id)
                ->whereNull('settled_at')
                ->whereNull('payroll_record_id')
                ->where('created_at', '<=', $this->end_date->copy()->endOfDay())
                ->get(['id', 'amount']);
            $commissionClawback = (float) max(0, -$adjustments->sum('amount'));

            // 3. Giảm trừ vi phạm kỷ luật: chỉ biên bản đã quyết phạt mà QUÁ HẠN NỘP (2 ngày)
            //     chưa nộp; đã nộp trực tiếp (paid) thì không trừ. Xem Penalty::scopeDeductibleFor.
            $penalties = Penalty::where('user_id', $user->id)
                ->deductibleFor($this)
                ->get(['id', 'amount']);
            $penaltyDeduction = (float) $penalties->sum('amount');

            // 3.1. Giảm trừ khi có Giáo viên Nước ngoài (GVNN) cùng dạy trong ca
            $foreignTeacherSessionsCount = 0;
            $foreignTeacherRate = $settings['foreign_teacher_deduction_rate'];
            foreach ($timesheets as $ts) {
                $foreignTeacherId = $ts->classModel?->foreign_teacher_id;
                if ($foreignTeacherId && $foreignTeacherId !== $user->id) {
                    $hasForeignTeacher = TeacherTimesheet::where('class_id', $ts->class_id)
                        ->where('teaching_date', $ts->teaching_date)
                        ->where('user_id', $foreignTeacherId)
                        ->where('status', 'valid')
                        ->exists();

                    if ($hasForeignTeacher) {
                        $foreignTeacherSessionsCount++;
                    }
                }
            }
            $foreignTeacherDeduction = $foreignTeacherSessionsCount * $foreignTeacherRate;

            // 4. Lương cơ bản và phụ cấp từ User (công thức lương Q3 chờ BA chốt — giữ công thức hiện tại)
            $baseSalary = (float) ($user->base_salary > 0 ? $user->base_salary : 0);
            $allowance = $existing?->allowance_override !== null
                ? (float) $existing->allowance_override
                : (($baseSalary > 0) ? $settings['allowance_amount'] : 0);
            $kpiBonus = ($actualHours >= $settings['kpi_bonus_hours_threshold']) ? $settings['kpi_bonus_amount'] : 0;
            $insuranceDeduction = ($baseSalary > 0) ? ($baseSalary * $settings['insurance_rate_percent'] / 100) : 0;
            $taxDeduction = 0;

            // Khoản Kế toán điều chỉnh tay trên phiếu lương được giữ qua các lần tính lại.
            $otherBonus = (float) ($existing?->other_bonus ?? 0);
            $otherDeduction = (float) ($existing?->other_deduction ?? 0);

            // Bỏ qua nếu nhân sự không có khoản nào phát sinh trong kỳ
            if ($baseSalary == 0 && $actualHours == 0 && $commissionBonus == 0 && $commissionClawback == 0
                && $otherBonus == 0 && $otherDeduction == 0) {
                continue;
            }

            $record = PayrollRecord::updateOrCreate(
                [
                    'payroll_period_id' => $this->id,
                    'user_id' => $user->id,
                ],
                [
                    'department' => $user->department ?? 'fulltime',
                    'base_salary' => $baseSalary,
                    'standard_hours' => ($baseSalary > 0) ? 40 : 0,
                    'actual_hours' => $actualHours,
                    'teaching_salary' => $teachingSalary,
                    'kpi_bonus' => $kpiBonus,
                    // A6: không tính hoa hồng tái tục. Thưởng tái tục (nếu có) chờ BA chốt Q3 → giữ 0.
                    'renew_bonus' => 0,
                    'commission_bonus' => $commissionBonus,
                    'commission_base' => $commissionBase,
                    'commission_clawback' => $commissionClawback,
                    'allowance' => $allowance,
                    'penalty_deduction' => $penaltyDeduction,
                    'foreign_teacher_sessions_count' => $foreignTeacherSessionsCount,
                    'foreign_teacher_deduction_rate' => $foreignTeacherRate,
                    'foreign_teacher_deduction' => $foreignTeacherDeduction,
                    'insurance_deduction' => $insuranceDeduction,
                    'tax_deduction' => $taxDeduction,
                    'notes' => "Tự động tính: {$actualHours}h dạy (".number_format($teachingSalary).'đ) + Hoa hồng '.number_format($commissionBonus).'đ'
                        .($commissionClawback > 0 ? ' - Thu hồi HH '.number_format($commissionClawback).'đ' : '')
                        .' - Phạt '.number_format($penaltyDeduction).'đ'
                        .($foreignTeacherDeduction > 0 ? ' - Trừ GVNN '.number_format($foreignTeacherDeduction).'đ' : ''),
                ]
            );
            $record->calculateNetSalary();
            $record->save();

            if ($penalties->isNotEmpty()) {
                Penalty::whereKey($penalties->modelKeys())
                    ->toBase()
                    ->update(['payroll_record_id' => $record->id]);
            }
            if ($adjustments->isNotEmpty()) {
                CommissionAdjustment::whereKey($adjustments->modelKeys())
                    ->toBase()
                    ->update(['payroll_record_id' => $record->id]);
            }

            $producedUserIds[] = $user->id;
        }

        // Nhân sự không còn đủ điều kiện ở lần tính này không được giữ bản ghi cũ.
        $this->records()->whereNotIn('user_id', $producedUserIds)->delete();

        $this->status = 'reviewing';
        $this->calculated_at = now();
        $this->refreshTotals();
    }
}
