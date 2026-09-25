<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRecord extends Model
{
    use HasFactory;

    protected $table = 'payroll_records';

    protected $fillable = [
        'payroll_period_id',
        'user_id',
        'department',
        'base_salary',
        'standard_hours',
        'actual_hours',
        'overtime_hours',
        'teaching_salary',
        'kpi_bonus',
        'renew_bonus',
        'commission_bonus',
        'commission_base',
        'commission_clawback',
        'allowance',
        'allowance_override',
        'other_bonus',
        'other_deduction',
        'insurance_deduction',
        'tax_deduction',
        'penalty_deduction',
        'foreign_teacher_sessions_count',
        'foreign_teacher_deduction_rate',
        'foreign_teacher_deduction',
        'net_salary',
        'status',
        'notes',
        'adjustment_notes',
    ];

    protected $casts = [
        'base_salary' => 'decimal:2',
        'standard_hours' => 'decimal:2',
        'actual_hours' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'teaching_salary' => 'decimal:2',
        'kpi_bonus' => 'decimal:2',
        'renew_bonus' => 'decimal:2',
        'commission_bonus' => 'decimal:2',
        'commission_base' => 'decimal:2',
        'commission_clawback' => 'decimal:2',
        'allowance' => 'decimal:2',
        'allowance_override' => 'decimal:2',
        'other_bonus' => 'decimal:2',
        'other_deduction' => 'decimal:2',
        'insurance_deduction' => 'decimal:2',
        'tax_deduction' => 'decimal:2',
        'penalty_deduction' => 'decimal:2',
        'foreign_teacher_sessions_count' => 'integer',
        'foreign_teacher_deduction_rate' => 'decimal:2',
        'foreign_teacher_deduction' => 'decimal:2',
        'net_salary' => 'decimal:2',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Tổng thu nhập (gross): mọi khoản cộng của bản ghi lương.
     */
    public function getGrossIncomeAttribute(): float
    {
        return (float) $this->base_salary + (float) $this->teaching_salary + (float) $this->kpi_bonus
            + (float) $this->renew_bonus + (float) $this->commission_bonus + (float) $this->allowance
            + (float) $this->other_bonus;
    }

    /**
     * Tổng khấu trừ: BHXH + thuế TNCN + phạt + giảm trừ GVNN + thu hồi hoa hồng + khấu trừ khác.
     */
    public function getTotalDeductionsAttribute(): float
    {
        return (float) $this->insurance_deduction + (float) $this->tax_deduction
            + (float) $this->penalty_deduction + (float) $this->foreign_teacher_deduction
            + (float) $this->commission_clawback + (float) $this->other_deduction;
    }

    /**
     * Các khoản cộng trên phiếu lương (dùng chung cho màn phiếu lương và "Lương của tôi").
     *
     * @return list<array{key: string, label: string, amount: float, hint: ?string}>
     */
    public function earningLines(): array
    {
        $hours = rtrim(rtrim(number_format((float) $this->actual_hours, 2, '.', ''), '0'), '.');

        return array_values(array_filter([
            ['key' => 'base_salary', 'label' => 'Lương cơ bản / cứng', 'amount' => (float) $this->base_salary, 'hint' => null],
            ['key' => 'teaching_salary', 'label' => "Thù lao giờ dạy ({$hours}h)", 'amount' => (float) $this->teaching_salary, 'hint' => 'Theo chấm công hợp lệ × đơn giá hiệu lực tại ngày dạy'],
            ['key' => 'kpi_bonus', 'label' => 'Thưởng KPI', 'amount' => (float) $this->kpi_bonus, 'hint' => null],
            ['key' => 'allowance', 'label' => 'Phụ cấp', 'amount' => (float) $this->allowance, 'hint' => $this->allowance_override !== null ? 'Kế toán điều chỉnh tay' : null],
            ['key' => 'commission_bonus', 'label' => 'Hoa hồng tuyển sinh (khách mới)', 'amount' => (float) $this->commission_bonus,
                'hint' => (float) $this->commission_base > 0 ? 'Căn cứ thực thu '.number_format((float) $this->commission_base, 0, ',', '.').'đ' : null],
            // renew_bonus luôn 0 (A6: không tính tái tục) — chỉ hiện nếu dữ liệu cũ có giá trị
            (float) $this->renew_bonus != 0.0 ? ['key' => 'renew_bonus', 'label' => 'Thưởng tái tục (dữ liệu cũ)', 'amount' => (float) $this->renew_bonus, 'hint' => null] : null,
            ['key' => 'other_bonus', 'label' => 'Thưởng / cộng khác', 'amount' => (float) $this->other_bonus, 'hint' => null],
        ]));
    }

    /**
     * Các khoản trừ trên phiếu lương.
     *
     * @return list<array{key: string, label: string, amount: float, hint: ?string}>
     */
    public function deductionLines(): array
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

    public function calculateNetSalary(): void
    {
        $settings = PayrollPeriod::payrollSettings();
        $this->foreign_teacher_deduction = $this->foreign_teacher_deduction > 0
            ? $this->foreign_teacher_deduction
            : ($this->foreign_teacher_sessions_count * ($this->foreign_teacher_deduction_rate ?: $settings['foreign_teacher_deduction_rate']));

        $this->net_salary = max(0, $this->gross_income - $this->total_deductions);
    }
}
