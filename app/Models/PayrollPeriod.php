<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollPeriod extends Model
{
    use HasFactory;

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
    ];

    protected $casts = [
        'month' => 'integer',
        'year' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'total_staff' => 'integer',
        'total_hours' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function records(): HasMany
    {
        return $this->hasMany(PayrollRecord::class, 'payroll_period_id');
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
            'draft' => 'Đang tính toán (Draft)',
            'reviewing' => 'Chờ Kế toán duyệt',
            'approved' => 'Đã duyệt (Approved)',
            'paid' => 'Đã chi trả',
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
        $settings = self::payrollSettings();
        $users = User::where('is_active', true)->get();
        $totalHours = 0;
        $totalAmount = 0;
        $staffCount = 0;

        foreach ($users as $user) {
            // 1. Giờ dạy thực tế từ bảng chấm công
            $timesheets = TeacherTimesheet::where('user_id', $user->id)
                ->whereBetween('teaching_date', [$this->start_date, $this->end_date])
                ->where('status', 'valid')
                ->with('classModel')
                ->get();

            $actualHours = (float) $timesheets->sum('hours');
            $teachingSalary = (float) $timesheets->sum(function ($ts) use ($user) {
                $rate = $ts->hourly_rate > 0 ? $ts->hourly_rate : ($user->hourly_rate > 0 ? $user->hourly_rate : 250000);

                return $ts->hours * $rate;
            });

            // 2. Hoa hồng tuyển sinh CRM (Deal Won trong kỳ; lead cũ chưa có
            //     converted_at thì lùi về created_at để khớp Báo cáo CRM)
            $wonRevenue = (float) CrmCustomer::where(function ($query) use ($user) {
                $query->where('commission_user_id', $user->id)
                    ->orWhere(function ($legacy) use ($user) {
                        $legacy->whereNull('commission_user_id')
                            ->where('assigned_user_id', $user->id);
                    });
            })
                ->where('stage', 'won')
                ->where(function ($query) {
                    $query->whereBetween('converted_at', [$this->start_date->copy()->startOfDay(), $this->end_date->copy()->endOfDay()])
                        ->orWhere(fn ($legacy) => $legacy
                            ->whereNull('converted_at')
                            ->whereBetween('created_at', [$this->start_date->copy()->startOfDay(), $this->end_date->copy()->endOfDay()]));
                })
                ->sum('deal_value');

            $tier = CommissionTier::matchForRevenue($wonRevenue);

            $commissionBonus = 0;
            if ($wonRevenue > 0 && $tier) {
                $commissionBonus = ($wonRevenue * (float) $tier->new_sale_percent / 100) + (float) ($tier->bonus_amount ?? 0);
            }

            // 3. Giảm trừ vi phạm kỷ luật trong kỳ (chỉ biên bản đã "quyết phạt";
            //     nộp trực tiếp đã đóng bằng status paid nên không trừ lương nữa)
            $penaltyDeduction = (float) Penalty::where('user_id', $user->id)
                ->whereIn('status', Penalty::payableStatuses())
                ->whereBetween('violation_date', [$this->start_date, $this->end_date])
                ->sum('amount');

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

            // 4. Lương cơ bản và phụ cấp từ User
            $baseSalary = (float) ($user->base_salary > 0 ? $user->base_salary : 0);
            $allowance = ($baseSalary > 0) ? $settings['allowance_amount'] : 0;
            $kpiBonus = ($actualHours >= $settings['kpi_bonus_hours_threshold']) ? $settings['kpi_bonus_amount'] : 0;
            $insuranceDeduction = ($baseSalary > 0) ? ($baseSalary * $settings['insurance_rate_percent'] / 100) : 0;
            $taxDeduction = 0;

            // 5. Thực lĩnh
            $gross = $baseSalary + $teachingSalary + $kpiBonus + $commissionBonus + $allowance;
            $netSalary = max(0, $gross - $penaltyDeduction - $foreignTeacherDeduction - $insuranceDeduction - $taxDeduction);

            // Bỏ qua nếu nhân sự không có lương cứng, không có giờ dạy và không có hoa hồng
            if ($baseSalary == 0 && $actualHours == 0 && $commissionBonus == 0) {
                continue;
            }

            PayrollRecord::updateOrCreate(
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
                    'commission_bonus' => $commissionBonus,
                    'allowance' => $allowance,
                    'penalty_deduction' => $penaltyDeduction,
                    'foreign_teacher_sessions_count' => $foreignTeacherSessionsCount,
                    'foreign_teacher_deduction_rate' => $foreignTeacherRate,
                    'foreign_teacher_deduction' => $foreignTeacherDeduction,
                    'insurance_deduction' => $insuranceDeduction,
                    'tax_deduction' => $taxDeduction,
                    'net_salary' => $netSalary,
                    'notes' => "Tự động tính: {$actualHours}h dạy (".number_format($teachingSalary).'đ) + Hoa hồng '.number_format($commissionBonus).'đ - Phạt '.number_format($penaltyDeduction).'đ'.($foreignTeacherDeduction > 0 ? ' - Trừ GVNN '.number_format($foreignTeacherDeduction).'đ' : ''),
                ]
            );

            $totalHours += $actualHours;
            $totalAmount += $netSalary;
            $staffCount++;
        }

        $this->update([
            'status' => 'reviewing',
            'total_staff' => $staffCount,
            'total_hours' => $totalHours,
            'total_amount' => $totalAmount,
        ]);
    }
}
