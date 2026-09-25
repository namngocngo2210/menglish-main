<?php

namespace App\Services;

use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\KpiCriterion;
use App\Models\KpiEvaluation;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Student;
use App\Models\TuitionReceipt;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Các thành phần công thức lương Q3 (A6 25/09/2026) — dùng trong PayrollPeriod::calculatePayrollForPeriod():
 * loại nhân sự, số HS giữ được (KPI Part-time), KPI Học vụ tự động, thưởng tái tục theo lớp.
 */
class PayrollFormulaService
{
    /** Loại hợp đồng (users.contract_type) được hiểu là part-time. */
    public const PARTTIME_CONTRACTS = ['bán thời gian', 'cộng tác viên', 'parttime', 'part-time'];

    public const FULLTIME_CONTRACTS = ['toàn thời gian', 'fulltime', 'full-time'];

    public const DEPARTMENTS = ['teacher', 'fulltime', 'academic', 'operations'];

    /**
     * Loại nhân sự trên phiếu lương, suy từ vai trò + loại hợp đồng:
     * - Học thuật (academic_lead) → Full-time, bảng "Khối Học thuật"; Học vụ (academic_staff) → Full-time, bảng "Học vụ & Vận hành".
     * - teacher_parttime → Part-time; teacher_fulltime → Full-time.
     * - teacher / assistant (và nhân sự chưa gán vai trò): HĐ "Bán thời gian"/"Cộng tác viên" → Part-time;
     *   "Toàn thời gian" → Full-time; chưa rõ (Thử việc / trống) → có lương cơ bản thì Full-time, không thì Part-time.
     * - Sale → Full-time (có hoa hồng); nhân sự khác → Full-time, bảng theo users.department (mặc định Vận hành).
     *
     * @return array{employee_type: string, salary_role: string, department: string}
     */
    public function profile(User $user): array
    {
        $roles = $user->relationLoaded('roles') ? $user->roles->pluck('name') : $user->getRoleNames();
        $has = fn (string $role) => $roles->contains($role);
        $fallbackDepartment = in_array($user->department, self::DEPARTMENTS, true) ? $user->department : 'operations';

        if ($has('academic_lead')) {
            return ['employee_type' => PayrollRecord::TYPE_FULLTIME, 'salary_role' => 'academic_lead', 'department' => 'academic'];
        }
        if ($has('academic_staff')) {
            return ['employee_type' => PayrollRecord::TYPE_FULLTIME, 'salary_role' => 'academic_staff', 'department' => 'operations'];
        }
        if ($has('teacher_parttime')) {
            return ['employee_type' => PayrollRecord::TYPE_PARTTIME, 'salary_role' => 'teacher_parttime', 'department' => 'teacher'];
        }
        if ($has('teacher_fulltime')) {
            return ['employee_type' => PayrollRecord::TYPE_FULLTIME, 'salary_role' => 'teacher_fulltime', 'department' => 'fulltime'];
        }
        // Nhân sự chưa gán vai trò (dữ liệu cũ) xét như giáo viên: theo hợp đồng / lương cơ bản.
        if ($has('teacher') || $has('assistant') || $roles->isEmpty()) {
            $contract = mb_strtolower(trim((string) $user->contract_type));
            $partTime = in_array($contract, self::PARTTIME_CONTRACTS, true)
                || (! in_array($contract, self::FULLTIME_CONTRACTS, true) && (float) $user->base_salary <= 0);

            return $partTime
                ? ['employee_type' => PayrollRecord::TYPE_PARTTIME, 'salary_role' => 'teacher_parttime', 'department' => 'teacher']
                : ['employee_type' => PayrollRecord::TYPE_FULLTIME, 'salary_role' => 'teacher_fulltime', 'department' => 'fulltime'];
        }
        if ($has('sales_consultant')) {
            return ['employee_type' => PayrollRecord::TYPE_FULLTIME, 'salary_role' => 'sales', 'department' => $fallbackDepartment];
        }

        return ['employee_type' => PayrollRecord::TYPE_FULLTIME, 'salary_role' => 'staff', 'department' => $fallbackDepartment];
    }

    /**
     * Học viên của các lớp tại đầu kỳ và số học viên nghỉ (Thôi học…) tới cuối kỳ.
     * Mẫu số = HS có lượt xếp lớp hiệu lực tại ngày đầu kỳ (xếp lớp ≤ ngày đầu kỳ, chưa thôi học trước đó).
     * Nghỉ = trong số đó, lượt xếp lớp chuyển "dropped" hoặc hồ sơ sang trạng thái mất (config) trước hết ngày cuối kỳ.
     *
     * @param  array<int>  $classIds
     * @return array{base: int, lost: int, retained: int, base_ids: array<int>, lost_ids: array<int>}
     */
    public function retentionFor(array $classIds, CarbonInterface $start, CarbonInterface $end): array
    {
        if ($classIds === []) {
            return ['base' => 0, 'lost' => 0, 'retained' => 0, 'base_ids' => [], 'lost_ids' => []];
        }

        $startDay = $start->copy()->startOfDay();
        $endDay = $end->copy()->endOfDay();
        $lostStatuses = (array) config('payroll.retention.lost_statuses', [Student::STATUS_DROPPED]);

        $enrollments = ClassEnrollment::with('student:id,status,updated_at')
            ->whereIn('class_id', $classIds)
            ->where(fn ($q) => $q->whereDate('enrolled_at', '<=', $startDay->toDateString())
                ->orWhere(fn ($q) => $q->whereNull('enrolled_at')->where('created_at', '<=', $startDay->copy()->endOfDay())))
            ->get(['id', 'student_id', 'class_id', 'status', 'enrolled_at', 'updated_at']);

        $base = [];
        $lost = [];
        foreach ($enrollments as $enrollment) {
            $student = $enrollment->student;
            if (! $student) {
                continue;
            }
            $enrollmentDroppedAt = $enrollment->status === Student::ENROLLMENT_DROPPED ? $enrollment->updated_at : null;
            $studentLostAt = in_array($student->status, $lostStatuses, true) ? $student->updated_at : null;

            // Đã nghỉ trước đầu kỳ → không thuộc mẫu số.
            if (($enrollmentDroppedAt && $enrollmentDroppedAt->lt($startDay)) || ($studentLostAt && $studentLostAt->lt($startDay))) {
                continue;
            }
            $id = (int) $student->id;
            $base[$id] = true;
            if (($enrollmentDroppedAt && $enrollmentDroppedAt->lte($endDay)) || ($studentLostAt && $studentLostAt->lte($endDay))) {
                $lost[$id] = true;
            }
        }

        // HS còn học ở lớp khác của cùng GV không tính là nghỉ.
        foreach (array_keys($lost) as $id) {
            $stillActive = $enrollments->first(fn ($e) => (int) $e->student_id === $id && $e->status !== Student::ENROLLMENT_DROPPED
                && ! in_array($e->student?->status, $lostStatuses, true));
            if ($stillActive) {
                unset($lost[$id]);
            }
        }

        return [
            'base' => count($base),
            'lost' => count($lost),
            'retained' => count($base) - count($lost),
            'base_ids' => array_keys($base),
            'lost_ids' => array_keys($lost),
        ];
    }

    /**
     * Lớp tính KPI giữ HS của GV part-time: lớp GV là GV chính, cộng lớp có ca dạy hợp lệ trong kỳ
     * (config payroll.retention.class_scope = main_teacher_or_taught).
     *
     * @param  array<int>  $taughtClassIds
     * @return array<int>
     */
    public function retentionClassIds(User $user, array $taughtClassIds): array
    {
        $owned = ClassModel::where('teacher_id', $user->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $scope = config('payroll.retention.class_scope', 'main_teacher_or_taught');

        return array_values(array_unique($scope === 'main_teacher' ? $owned : array_merge($owned, array_map('intval', $taughtClassIds))));
    }

    /** Bảng % thưởng tái tục theo số HS nghỉ: [số nghỉ => ['percent' => float, 'pending' => bool]]. */
    public function renewalTable(): array
    {
        $table = PayrollPeriod::payrollSettings()['renewal_table'];
        ksort($table);

        return $table;
    }

    /** % thưởng tái tục cho số HS nghỉ; ngoài bảng → beyond_percent (chờ BA). */
    public function renewalPercent(int $quits): array
    {
        $table = $this->renewalTable();
        if (isset($table[$quits])) {
            return ['percent' => (float) $table[$quits]['percent'], 'pending' => (bool) $table[$quits]['pending']];
        }

        return ['percent' => (float) PayrollPeriod::payrollSettings()['renewal_beyond_percent'], 'pending' => true];
    }

    /**
     * Thưởng tái tục: với mỗi lớp GV phụ trách (GV chính) có HS đầu kỳ: % (theo số HS nghỉ trong kỳ) × doanh thu lớp
     * (phiếu thu đã duyệt trong kỳ của các khoản học phí gắn lớp, trừ tiền nhận chuyển nhượng).
     *
     * @return array{amount: float, classes: list<array<string, mixed>>}
     */
    public function renewalBonusFor(User $user, CarbonInterface $start, CarbonInterface $end): array
    {
        $classes = ClassModel::where('teacher_id', $user->id)->orderBy('id')->get(['id', 'code', 'name']);
        $rows = [];
        $total = 0.0;

        foreach ($classes as $class) {
            $retention = $this->retentionFor([(int) $class->id], $start, $end);
            if ($retention['base'] === 0) {
                continue;
            }
            $revenue = $this->classRevenue((int) $class->id, $start, $end);
            $rate = $this->renewalPercent($retention['lost']);
            $amount = round($revenue * $rate['percent'] / 100, 0);
            $total += $amount;
            $rows[] = [
                'class_id' => (int) $class->id,
                'class' => trim(($class->code ? $class->code.' — ' : '').$class->name),
                'base' => $retention['base'],
                'quits' => $retention['lost'],
                'percent' => $rate['percent'],
                'pending' => $rate['pending'],
                'revenue' => $revenue,
                'amount' => $amount,
            ];
        }

        return ['amount' => $total, 'classes' => $rows];
    }

    /** Doanh thu lớp trong kỳ: phiếu thu đã duyệt (amount > 0, không phải nhận chuyển nhượng) của khoản học phí gắn lớp. */
    public function classRevenue(int $classId, CarbonInterface $start, CarbonInterface $end): float
    {
        return (float) TuitionReceipt::query()
            ->where('status', TuitionReceipt::STATUS_APPROVED)
            ->where('amount', '>', 0)
            ->where(fn ($q) => $q->whereNull('transaction_code')->orWhere('transaction_code', 'not like', 'XFER-IN-%'))
            ->whereBetween('approved_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->whereHas('tuition', fn ($q) => $q->where('class_id', $classId))
            ->sum('amount');
    }

    /**
     * KPI Học vụ tự động: quỹ × điểm KPI có trọng số của đánh giá tháng đã chốt.
     * Điểm = Σ(điểm mục × trọng số) / Σ trọng số các mục đang áp dụng (mục chưa chấm = 0).
     *
     * @return array{amount: float, score: ?float, fund: float, evaluation_id: ?int, items: list<array<string, mixed>>}
     */
    public function academicKpiFor(User $user, int $month, int $year): array
    {
        $fund = (float) PayrollPeriod::payrollSettings()['academic_kpi_fund'];
        $evaluation = KpiEvaluation::with('items')
            ->where('user_id', $user->id)->where('month', $month)->where('year', $year)
            ->where('status', 'confirmed')
            ->first();
        if (! $evaluation) {
            return ['amount' => 0.0, 'score' => null, 'fund' => $fund, 'evaluation_id' => null, 'items' => []];
        }

        $criteria = KpiCriterion::active()->ordered()->get();
        $weightTotal = (float) $criteria->sum('weight');
        $scores = $evaluation->items->keyBy('kpi_criterion_id');
        $weighted = 0.0;
        $items = [];
        foreach ($criteria as $criterion) {
            $score = (float) ($scores->get($criterion->id)?->score ?? 0);
            $weighted += $score * (float) $criterion->weight;
            $items[] = [
                'code' => $criterion->code, 'group' => $criterion->group_name, 'name' => $criterion->name,
                'weight' => (float) $criterion->weight, 'score' => $score,
                'amount' => $weightTotal > 0 ? round($fund * (float) $criterion->weight / $weightTotal * $score / 100, 0) : 0,
            ];
        }
        $score = $weightTotal > 0 ? round($weighted / $weightTotal, 2) : 0.0;

        return [
            'amount' => round($fund * $score / 100, 0),
            'score' => $score,
            'fund' => $fund,
            'evaluation_id' => (int) $evaluation->id,
            'items' => $items,
        ];
    }

    /** Học viên theo id (hiển thị căn cứ KPI giữ HS). */
    public function studentNames(array $ids): Collection
    {
        return Student::whereIn('id', $ids)->orderBy('name')->get(['id', 'code', 'name']);
    }
}
