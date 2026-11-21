<?php

namespace App\Support\Dashboard;

use App\Models\ClassModel;
use App\Models\CrmCustomer;
use App\Models\PayrollPeriod;
use App\Models\Penalty;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Support\DataScope;
use App\Support\Money;
use App\Support\Roles;
use App\Support\TuitionBranchScope;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Widget nghiệp vụ trên Tổng quan (TK-2026-0154): chỉ ĐỌC số đếm, luôn hiện kể cả 0 (kèm dòng trống), theo quyền + phạm vi
 * chi nhánh của người xem. Admin thêm khối tổng hợp toàn hệ thống (4 số độc lập).
 *
 * Quy ước ánh xạ từ US sang hệ thống:
 *  - Vi phạm cần xử lý = biên bản chưa chốt phạt: Ghi nhận (pending/explained) + Đã chốt lỗi (confirmed).
 *  - Bảng lương chờ xử lý = kỳ lương có hôm nay nằm trong kỳ, trạng thái Đang tính (draft) / Đã chốt (closed).
 *  - Khách tuyển sinh đang theo dõi = nhóm mới (new), đang tư vấn (consulting), test (test_scheduled, testing).
 *  - Phiếu thu chờ đối soát = phiếu thu tiền mặt chưa có người xác nhận nộp về TK công ty (deposited_by trống);
 *    khác "Phiếu thu chờ duyệt" (status pending của Admin).
 */
final class BusinessWidgets
{
    public const LEAD_STAGES = ['new', 'consulting', 'test_scheduled', 'testing'];

    /**
     * @param  Closure(string, array<int, string>=): bool  $canOpen
     */
    public function __construct(private readonly User $user, private readonly Closure $canOpen) {}

    /** @return list<array<string, mixed>> */
    public function build(string $kind): array
    {
        $user = $this->user;
        $ops = in_array($kind, ['admin', 'manager'], true);
        $registrar = $kind === 'personal' && $user->hasRole(Roles::ACADEMIC_STAFF);
        $widgets = [];

        if ($ops && $user->can('violation.view')) {
            $widgets[] = $this->violations();
        }
        if ($ops && $user->can('payroll.view')) {
            $widgets[] = $this->payroll();
        }
        if (($ops || $kind === 'personal') && $user->can('lead.view')) {
            $widgets[] = $this->leads();
        }
        if (($ops || $kind === 'personal' || $kind === 'sales') && ($user->can('student.view') || $kind === 'sales')) {
            $widgets[] = $this->birthdays();
        }
        if ($kind === 'personal' && $user->can('tuition.view')) {
            $widgets[] = $this->overdueTuition();
        }
        if (($ops || $registrar) && $user->can('tuition.view')) {
            $widgets[] = $this->receiptsToReconcile();
        }

        return $widgets;
    }

    /**
     * Khối tổng hợp toàn hệ thống (chỉ Admin): 4 số độc lập, không phụ thuộc chi nhánh của người xem.
     *
     * @return list<array<string, mixed>>|null
     */
    public function aggregate(string $kind): ?array
    {
        if ($kind !== 'admin') {
            return null;
        }

        return [
            ['label' => 'Học viên đang học', 'value' => Student::query()->where('status', 'studying')->count()],
            ['label' => 'Khách tuyển sinh đang theo dõi', 'value' => CrmCustomer::query()->whereIn('stage', self::LEAD_STAGES)->count()],
            ['label' => 'Học phí quá hạn', 'value' => $this->overdueQuery(null)->count()],
            ['label' => 'Lớp đang chạy', 'value' => ClassModel::query()->where('status', 'active')->count()],
        ];
    }

    private function widget(string $key, string $label, int $value, string $empty, ?string $hint, ?string $href, string $icon): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'hint' => $value > 0 ? $hint : $empty,
            'href' => $href,
            'icon' => $icon,
            'tone' => $value > 0 ? 'today' : 'info',
        ];
    }

    private function open(string $route): ?string
    {
        return ($this->canOpen)($route) ? route($route) : null;
    }

    private function violations(): array
    {
        // Phạm vi: Penalty tự giới hạn theo violation.scope_* (ScopedToViewer).
        $count = Penalty::query()->whereIn('status', ['pending', 'explained', 'confirmed'])->count();

        return $this->widget('violations', 'Vi phạm cần xử lý', $count, 'Không có vi phạm cần xử lý', 'Ghi nhận / đã chốt lỗi, chưa chốt phạt', $this->open('penalties.index'), 'gavel');
    }

    private function payroll(): array
    {
        $today = today()->toDateString();
        $count = PayrollPeriod::query()
            ->whereIn('status', [PayrollPeriod::STATUS_DRAFT, PayrollPeriod::STATUS_CLOSED])
            ->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)
            ->count();

        return $this->widget('payroll', 'Bảng lương chờ xử lý', $count, 'Không có bảng lương chờ xử lý', 'Kỳ lương hiện tại đang tính / đã chốt', $this->open('payroll.periods.index'), 'payments');
    }

    private function leads(): array
    {
        $count = CrmCustomer::query()->visibleTo($this->user)->whereIn('stage', self::LEAD_STAGES)->count();

        return $this->widget('leads', 'Khách tuyển sinh đang theo dõi', $count, 'Không có khách đang theo dõi', 'Mới / đang tư vấn / hẹn test', $this->open('crm.customers.index'), 'person_search');
    }

    private function birthdays(): array
    {
        $days = collect(range(0, 6))->map(fn (int $i) => now()->startOfWeek()->addDays($i)->format('m-d'))->all();
        // Tính ở PHP để chạy được cả SQLite lẫn MySQL.
        $count = TuitionBranchScope::students(Student::query(), DataScope::branchIds($this->user, 'student'))
            ->where('status', 'studying')->whereNotNull('dob')->get(['id', 'dob'])
            ->filter(fn (Student $s) => in_array($s->dob->format('m-d'), $days, true))->count();

        return $this->widget('birthdays', 'Sinh nhật học viên trong tuần', $count, 'Tuần này không có sinh nhật', 'Từ thứ Hai đến Chủ nhật', $this->open('students.index'), 'cake');
    }

    private function overdueQuery(?array $branchIds): Builder
    {
        return TuitionBranchScope::tuitions(StudentTuition::query()->whereHas('student'), $branchIds)->overdueNow();
    }

    private function overdueTuition(): array
    {
        $query = $this->overdueQuery(TuitionBranchScope::branchIds($this->user));
        $count = (clone $query)->count();

        return $this->widget('overdue_tuition', 'Học phí quá hạn cần nhắc', $count, 'Không có khoản quá hạn', 'Còn nợ '.Money::format((float) $query->sum('debt_amount')), $this->open('tuition.overdue'), 'alarm');
    }

    private function receiptsToReconcile(): array
    {
        $count = TuitionBranchScope::receipts(
            TuitionReceipt::query()->where('payment_method', 'cash')->whereIn('status', TuitionReceipt::REFERENCE_HOLDING_STATUSES)
                ->where('amount', '>', 0)->whereNull('deposited_by'),
            TuitionBranchScope::branchIds($this->user),
        )->count();

        return $this->widget('receipts_reconcile', 'Phiếu thu chờ đối soát', $count, 'Không có phiếu thu chờ đối soát', 'Tiền mặt chưa xác nhận nộp về TK công ty', $this->open('tuition.receipts.approve'), 'fact_check');
    }
}
