<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phiếu lương một người trong một kỳ. Từ Q3 (A6 25/09/2026) mỗi phiếu ghi rõ loại nhân sự:
 *
 * - Part-time (GV part-time): số buổi × đơn giá buổi riêng + KPI giữ HS (số HS giữ được × bậc Admin chọn)
 *   + lương buổi có GVNN (nhập tay, chờ BA) + phụ cấp tự do − phạt quá hạn − khấu trừ tự do. Không BHXH/Công đoàn.
 * - Full-time (GV full-time, Học vụ, Học thuật, Sale, nhân sự khác): lương cơ bản + KPI + hoa hồng + thưởng tái tục
 *   + phụ cấp tự do − BHXH − Công đoàn (tự động trên lương cơ bản) − thuế TNCN (nhập tay) − phạt − khấu trừ tự do.
 *
 * Khoản nhập tay (giữ khi tính lại): retention_tier, kpi_manual_amount, foreign_session_pay, tax_deduction,
 * manual_lines ([{kind: earning|deduction, label, amount}]), adjustment_notes.
 * Phiếu trước Q3 (employee_type NULL) hiển thị theo các cột cũ.
 */
class PayrollRecord extends Model
{
    use HasFactory;

    public const TYPE_PARTTIME = 'parttime';

    public const TYPE_FULLTIME = 'fulltime';

    public const TYPE_LABELS = [
        self::TYPE_PARTTIME => 'Part-time',
        self::TYPE_FULLTIME => 'Full-time',
    ];

    public const SALARY_ROLE_LABELS = [
        'teacher_parttime' => 'GV Part-time',
        'teacher_fulltime' => 'GV Full-time',
        'academic_staff' => 'Học vụ',
        'academic_lead' => 'Học thuật',
        'sales' => 'Tư vấn tuyển sinh',
        'staff' => 'Nhân sự khác',
    ];

    public const KPI_RETENTION = 'retention';

    public const KPI_MANUAL = 'manual';

    public const KPI_ACADEMIC = 'academic_kpi';

    protected $table = 'payroll_records';

    protected $fillable = [
        'payroll_period_id',
        'user_id',
        'department',
        'employee_type',
        'salary_role',
        'base_salary',
        'standard_hours',
        'actual_hours',
        'teaching_sessions',
        'retention_base_students',
        'retention_students',
        'retention_tier',
        'overtime_hours',
        'teaching_salary',
        'kpi_bonus',
        'kpi_source',
        'kpi_manual_amount',
        'kpi_score',
        'foreign_session_pay',
        'renew_bonus',
        'commission_bonus',
        'commission_base',
        'commission_clawback',
        'commission_deferred',
        'commission_percent',
        'commission_closed_count',
        'allowance',
        'allowance_override',
        'other_bonus',
        'other_deduction',
        'insurance_deduction',
        'union_deduction',
        'tax_deduction',
        'penalty_deduction',
        'foreign_teacher_sessions_count',
        'foreign_teacher_deduction_rate',
        'foreign_teacher_deduction',
        'net_salary',
        'status',
        'notes',
        'adjustment_notes',
        'manual_lines',
        'calculation_details',
    ];

    protected $casts = [
        'base_salary' => 'decimal:2',
        'standard_hours' => 'decimal:2',
        'actual_hours' => 'decimal:2',
        'teaching_sessions' => 'integer',
        'retention_base_students' => 'integer',
        'retention_students' => 'integer',
        'retention_tier' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'teaching_salary' => 'decimal:2',
        'kpi_bonus' => 'decimal:2',
        'kpi_manual_amount' => 'decimal:2',
        'kpi_score' => 'decimal:2',
        'foreign_session_pay' => 'decimal:2',
        'renew_bonus' => 'decimal:2',
        'commission_bonus' => 'decimal:2',
        'commission_base' => 'decimal:2',
        'commission_clawback' => 'decimal:2',
        'commission_deferred' => 'decimal:2',
        'commission_percent' => 'decimal:2',
        'commission_closed_count' => 'integer',
        'allowance' => 'decimal:2',
        'allowance_override' => 'decimal:2',
        'other_bonus' => 'decimal:2',
        'other_deduction' => 'decimal:2',
        'insurance_deduction' => 'decimal:2',
        'union_deduction' => 'decimal:2',
        'tax_deduction' => 'decimal:2',
        'penalty_deduction' => 'decimal:2',
        'foreign_teacher_sessions_count' => 'integer',
        'foreign_teacher_deduction_rate' => 'decimal:2',
        'foreign_teacher_deduction' => 'decimal:2',
        'net_salary' => 'decimal:2',
        'manual_lines' => 'array',
        'calculation_details' => 'array',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function commissionItems(): HasMany
    {
        return $this->hasMany(CommissionItem::class, 'payroll_record_id');
    }

    public function isPartTime(): bool
    {
        return $this->employee_type === self::TYPE_PARTTIME;
    }

    public function isFullTime(): bool
    {
        return $this->employee_type === self::TYPE_FULLTIME;
    }

    /** Phiếu tính theo công thức Q3 (có loại nhân sự) — phiếu cũ trước Q3 thì không. */
    public function usesQ3Formula(): bool
    {
        return $this->employee_type !== null;
    }

    public function getEmployeeTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->employee_type] ?? 'Trước Q3';
    }

    public function getSalaryRoleLabelAttribute(): string
    {
        return self::SALARY_ROLE_LABELS[$this->salary_role] ?? ($this->employee_type_label);
    }

    /**
     * "Trạng thái KPI" trên danh sách bảng lương (mockup): KPI của phiếu đã được chốt chưa.
     * Part-time: đã chọn bậc KPI giữ HS (không có HS đầu kỳ thì không áp dụng); Học vụ: đã có đánh giá KPI tháng
     * được chốt; GV Full-time / Học thuật / Sale / khác: đã nhập KPI tự do (kể cả 0đ). Phiếu trước Q3: không áp dụng.
     *
     * @return array{0: string, 1: string} [done|pending|na, nhãn]
     */
    public function getKpiStateAttribute(): array
    {
        if (! $this->usesQ3Formula()) {
            return ['na', 'Không áp dụng'];
        }

        $done = match ($this->kpi_source) {
            self::KPI_RETENTION => (int) $this->retention_base_students === 0 ? null : $this->retention_tier !== null,
            self::KPI_ACADEMIC => $this->kpi_score !== null,
            self::KPI_MANUAL => $this->kpi_manual_amount !== null,
            default => null,
        };

        return match ($done) {
            true => ['done', 'Đã chốt KPI'],
            false => ['pending', 'Chưa chốt KPI'],
            default => ['na', 'Không áp dụng'],
        };
    }

    /** @return list<array{kind: string, label: string, amount: float}> */
    public function manualLines(?string $kind = null): array
    {
        $lines = collect((array) ($this->manual_lines ?? []))
            ->filter(fn ($line) => is_array($line) && isset($line['kind'], $line['label']))
            ->map(fn (array $line) => ['kind' => (string) $line['kind'], 'label' => (string) $line['label'], 'amount' => (float) ($line['amount'] ?? 0)]);

        return $lines->when($kind !== null, fn ($c) => $c->where('kind', $kind))->values()->all();
    }

    /**
     * Tổng thu nhập (gross): mọi khoản cộng của bản ghi lương.
     */
    public function getGrossIncomeAttribute(): float
    {
        return (float) $this->base_salary + (float) $this->teaching_salary + (float) $this->kpi_bonus
            + (float) $this->renew_bonus + (float) $this->commission_bonus + (float) $this->allowance
            + (float) $this->other_bonus + (float) $this->foreign_session_pay;
    }

    /**
     * Tổng khấu trừ: BHXH + Công đoàn + thuế TNCN + phạt + (giảm trừ GVNN — dữ liệu cũ) + thu hồi hoa hồng + khấu trừ khác.
     */
    public function getTotalDeductionsAttribute(): float
    {
        return (float) $this->insurance_deduction + (float) $this->union_deduction + (float) $this->tax_deduction
            + (float) $this->penalty_deduction + (float) $this->foreign_teacher_deduction
            + (float) $this->commission_clawback + (float) $this->other_deduction;
    }

    /**
     * Các khoản cộng trên phiếu lương (dùng chung cho màn phiếu lương, "Lương của tôi").
     *
     * @return list<array{key: string, label: string, amount: float, hint: ?string}>
     */
    public function earningLines(): array
    {
        if (! $this->usesQ3Formula()) {
            return $this->legacyEarningLines();
        }

        $money = fn ($value) => number_format((float) $value, 0, ',', '.').'đ';
        $lines = [];

        if ($this->isPartTime()) {
            $lines[] = ['key' => 'teaching_salary', 'label' => 'Lương buổi dạy ('.(int) $this->teaching_sessions.' buổi)', 'amount' => (float) $this->teaching_salary,
                'hint' => 'Số buổi chấm công hợp lệ × đơn giá buổi riêng của GV hiệu lực tại ngày dạy'];
            $lines[] = ['key' => 'kpi_bonus', 'label' => 'KPI giữ học sinh', 'amount' => (float) $this->kpi_bonus,
                'hint' => $this->retention_tier !== null
                    ? (int) $this->retention_students.'/'.(int) $this->retention_base_students.' HS giữ được × '.$money($this->retention_tier).'/HS'
                    : (int) $this->retention_students.'/'.(int) $this->retention_base_students.' HS giữ được — chưa chọn bậc KPI'];
            $lines[] = ['key' => 'foreign_session_pay', 'label' => 'Buổi có GVNN (chờ BA chốt)', 'amount' => (float) $this->foreign_session_pay,
                'hint' => 'Kế toán nhập tay'.((int) $this->foreign_teacher_sessions_count > 0 ? ' · '.(int) $this->foreign_teacher_sessions_count.' buổi có GVNN cùng lớp trong kỳ' : '')];
        } else {
            $lines[] = ['key' => 'base_salary', 'label' => 'Lương cơ bản', 'amount' => (float) $this->base_salary, 'hint' => null];
            $lines[] = ['key' => 'kpi_bonus', 'label' => $this->kpi_source === self::KPI_ACADEMIC ? 'KPI Học vụ (6 nhóm / 15 mục)' : 'KPI', 'amount' => (float) $this->kpi_bonus,
                'hint' => match ($this->kpi_source) {
                    self::KPI_ACADEMIC => $this->kpi_score !== null
                        ? 'Quỹ '.$money(data_get($this->calculation_details, 'kpi.fund', 0)).' × '.rtrim(rtrim(number_format((float) $this->kpi_score, 2, ',', '.'), '0'), ',').'% điểm KPI tháng'
                        : 'Chưa có đánh giá KPI tháng đã chốt',
                    self::KPI_MANUAL => $this->kpi_manual_amount !== null ? 'Nhập tay' : 'Chưa nhập KPI',
                    default => null,
                }];
            if ($this->salary_role === 'sales' || (float) $this->commission_bonus != 0.0 || (float) $this->commission_deferred != 0.0) {
                $lines[] = $this->commissionLine();
            }
            if ((float) $this->renew_bonus != 0.0 || ! empty(data_get($this->calculation_details, 'renewal.classes'))) {
                $lines[] = ['key' => 'renew_bonus', 'label' => 'Thưởng tái tục', 'amount' => (float) $this->renew_bonus,
                    'hint' => '% theo số HS nghỉ của lớp phụ trách × doanh thu lớp trong kỳ'];
            }
        }

        if ($this->isPartTime() && ((float) $this->commission_bonus != 0.0 || (float) $this->commission_deferred != 0.0)) {
            $lines[] = $this->commissionLine();
        }

        foreach ($this->manualLines('earning') as $i => $line) {
            $lines[] = ['key' => 'manual_earning_'.$i, 'label' => $line['label'], 'amount' => $line['amount'], 'hint' => 'Khoản cộng tự do'];
        }
        if ((float) $this->other_bonus != 0.0) {
            $lines[] = ['key' => 'other_bonus', 'label' => 'Thưởng / cộng khác', 'amount' => (float) $this->other_bonus, 'hint' => null];
        }

        return $lines;
    }

    private function commissionLine(): array
    {
        $hint = [];
        if ((float) $this->commission_base > 0) {
            $hint[] = 'Căn cứ thực thu '.number_format((float) $this->commission_base, 0, ',', '.').'đ';
        }
        if ((float) $this->commission_deferred > 0) {
            $hint[] = 'Hoãn sang kỳ sau '.number_format((float) $this->commission_deferred, 0, ',', '.').'đ';
        }

        return ['key' => 'commission_bonus', 'label' => 'Hoa hồng tuyển sinh (khách mới)', 'amount' => (float) $this->commission_bonus,
            'hint' => $hint === [] ? null : implode(' · ', $hint)];
    }

    /**
     * Các khoản trừ trên phiếu lương.
     *
     * @return list<array{key: string, label: string, amount: float, hint: ?string}>
     */
    public function deductionLines(): array
    {
        if (! $this->usesQ3Formula()) {
            return $this->legacyDeductionLines();
        }

        $base = number_format((float) $this->base_salary, 0, ',', '.');
        $lines = [];
        if ($this->isFullTime()) {
            $lines[] = ['key' => 'insurance_deduction', 'label' => 'BHXH ('.$this->rateLabel('insurance').'% lương cơ bản)', 'amount' => (float) $this->insurance_deduction, 'hint' => 'Trên lương cơ bản '.$base.'đ'];
            $lines[] = ['key' => 'union_deduction', 'label' => 'Công đoàn ('.$this->rateLabel('union').'% lương cơ bản)', 'amount' => (float) $this->union_deduction, 'hint' => null];
            $lines[] = ['key' => 'tax_deduction', 'label' => 'Thuế TNCN', 'amount' => (float) $this->tax_deduction, 'hint' => 'Admin / Kế toán nhập tay'];
        }
        $lines[] = ['key' => 'penalty_deduction', 'label' => 'Phạt vi phạm (quá hạn nộp)', 'amount' => (float) $this->penalty_deduction, 'hint' => null];
        if ((float) $this->commission_clawback != 0.0) {
            $lines[] = ['key' => 'commission_clawback', 'label' => 'Thu hồi hoa hồng (hoàn phí / hủy hóa đơn)', 'amount' => (float) $this->commission_clawback, 'hint' => null];
        }
        if ((float) $this->foreign_teacher_deduction != 0.0) {
            $lines[] = ['key' => 'foreign_teacher_deduction', 'label' => 'Giảm trừ ca có GVNN (dữ liệu cũ)', 'amount' => (float) $this->foreign_teacher_deduction, 'hint' => null];
        }
        foreach ($this->manualLines('deduction') as $i => $line) {
            $lines[] = ['key' => 'manual_deduction_'.$i, 'label' => $line['label'], 'amount' => $line['amount'], 'hint' => 'Khoản trừ tự do'];
        }

        return $lines;
    }

    private function rateLabel(string $which): string
    {
        $rate = data_get($this->calculation_details, 'rates.'.$which)
            ?? PayrollPeriod::payrollSettings()[$which === 'insurance' ? 'insurance_rate_percent' : 'union_rate_percent'];

        return rtrim(rtrim(number_format((float) $rate, 2, ',', '.'), '0'), ',');
    }

    /** Phiếu trước Q3: giữ nguyên cách hiển thị cũ. */
    private function legacyEarningLines(): array
    {
        $hours = rtrim(rtrim(number_format((float) $this->actual_hours, 2, '.', ''), '0'), '.');

        return array_values(array_filter([
            ['key' => 'base_salary', 'label' => 'Lương cơ bản / cứng', 'amount' => (float) $this->base_salary, 'hint' => null],
            ['key' => 'teaching_salary', 'label' => "Thù lao giờ dạy ({$hours}h)", 'amount' => (float) $this->teaching_salary, 'hint' => 'Theo chấm công hợp lệ × đơn giá hiệu lực tại ngày dạy'],
            ['key' => 'kpi_bonus', 'label' => 'Thưởng KPI', 'amount' => (float) $this->kpi_bonus, 'hint' => null],
            ['key' => 'allowance', 'label' => 'Phụ cấp', 'amount' => (float) $this->allowance, 'hint' => $this->allowance_override !== null ? 'Kế toán điều chỉnh tay' : null],
            ['key' => 'commission_bonus', 'label' => 'Hoa hồng tuyển sinh (khách mới)', 'amount' => (float) $this->commission_bonus,
                'hint' => (float) $this->commission_base > 0 ? 'Căn cứ thực thu '.number_format((float) $this->commission_base, 0, ',', '.').'đ' : null],
            (float) $this->renew_bonus != 0.0 ? ['key' => 'renew_bonus', 'label' => 'Thưởng tái tục', 'amount' => (float) $this->renew_bonus, 'hint' => null] : null,
            ['key' => 'other_bonus', 'label' => 'Thưởng / cộng khác', 'amount' => (float) $this->other_bonus, 'hint' => null],
        ]));
    }

    private function legacyDeductionLines(): array
    {
        return [
            ['key' => 'insurance_deduction', 'label' => 'Bảo hiểm xã hội & y tế', 'amount' => (float) $this->insurance_deduction, 'hint' => null],
            ['key' => 'tax_deduction', 'label' => 'Thuế TNCN', 'amount' => (float) $this->tax_deduction, 'hint' => null],
            ['key' => 'penalty_deduction', 'label' => 'Phạt vi phạm (quá hạn nộp)', 'amount' => (float) $this->penalty_deduction, 'hint' => null],
            ['key' => 'foreign_teacher_deduction', 'label' => 'Giảm trừ ca có GVNN cùng dạy ('.(int) $this->foreign_teacher_sessions_count.' buổi)', 'amount' => (float) $this->foreign_teacher_deduction, 'hint' => null],
            ['key' => 'commission_clawback', 'label' => 'Thu hồi hoa hồng (hoàn phí)', 'amount' => (float) $this->commission_clawback, 'hint' => null],
            ['key' => 'other_deduction', 'label' => 'Khấu trừ khác', 'amount' => (float) $this->other_deduction, 'hint' => null],
        ];
    }

    /** Kỳ lương của bản ghi đã duyệt/đã chi trả → không được sửa. */
    public function isLocked(): bool
    {
        return in_array($this->period?->status, PayrollPeriod::LOCKED_STATUSES, true);
    }

    /**
     * Tính lại các khoản phụ thuộc khoản nhập tay (không cần quét lại dữ liệu kỳ): phụ cấp / khấu trừ tự do,
     * KPI giữ HS (số HS × bậc), KPI nhập tay; rồi thực lĩnh. Dùng khi Kế toán lưu phiếu và ở cuối lần tính kỳ.
     */
    public function applyManualInputs(): void
    {
        if (! $this->usesQ3Formula()) {
            $this->calculateNetSalary();

            return;
        }

        $this->allowance = round(collect($this->manualLines('earning'))->sum('amount'), 2);
        $this->other_deduction = round(collect($this->manualLines('deduction'))->sum('amount'), 2);

        if ($this->kpi_source === self::KPI_RETENTION) {
            $this->kpi_bonus = round((int) $this->retention_students * (float) ($this->retention_tier ?? 0), 2);
        } elseif ($this->kpi_source === self::KPI_MANUAL) {
            $this->kpi_bonus = (float) ($this->kpi_manual_amount ?? 0);
        }

        if ($this->isPartTime()) {
            // Part-time không trừ BHXH / Công đoàn / thuế TNCN (Q3).
            $this->insurance_deduction = 0;
            $this->union_deduction = 0;
            $this->tax_deduction = 0;
        }

        $this->calculateNetSalary();
    }

    public function calculateNetSalary(): void
    {
        $this->net_salary = max(0, $this->gross_income - $this->total_deductions);
    }
}
