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
        'allowance',
        'insurance_deduction',
        'tax_deduction',
        'penalty_deduction',
        'foreign_teacher_sessions_count',
        'foreign_teacher_deduction_rate',
        'foreign_teacher_deduction',
        'net_salary',
        'status',
        'notes',
    ];

    protected $casts = [
        'base_salary' => 'decimal:2',
        'standard_hours' => 'decimal:2',
        'actual_hours' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'teaching_salary' => 'decimal:2',
        'kpi_bonus' => 'decimal:2',
        'renew_bonus' => 'decimal:2',
        'allowance' => 'decimal:2',
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

    public function calculateNetSalary(): void
    {
        $settings = PayrollPeriod::payrollSettings();
        $gross = $this->base_salary + $this->teaching_salary + $this->kpi_bonus + $this->renew_bonus + $this->allowance;
        $foreignDeduction = $this->foreign_teacher_deduction > 0
            ? $this->foreign_teacher_deduction
            : ($this->foreign_teacher_sessions_count * ($this->foreign_teacher_deduction_rate ?: $settings['foreign_teacher_deduction_rate']));
        $this->foreign_teacher_deduction = $foreignDeduction;

        $deductions = $this->insurance_deduction + $this->tax_deduction + $this->penalty_deduction + $foreignDeduction;
        $this->net_salary = max(0, $gross - $deductions);
    }
}
