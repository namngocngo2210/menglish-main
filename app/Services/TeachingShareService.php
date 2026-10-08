<?php

namespace App\Services;

use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TeacherHourlyRate;
use App\Models\TeacherTimesheet;
use App\Models\TuitionRefundRequest;
use App\Services\Tuition\SessionLedger;
use Illuminate\Support\Collection;

/**
 * Lương đứng lớp của Học thuật kiêm nhiệm giảng dạy (bảng lương mẫu "Lớp theo tỷ lệ 40/60"): chấm công như GV part-time
 * (mỗi ca chấm công hợp lệ = 1 buổi, cùng quy tắc đi muộn / về sớm), tiền mỗi buổi = % × học phí theo buổi của các HS
 * thuộc lớp vào ngày dạy.
 *
 * Học phí theo buổi của một HS = khoản học phí của HS (ưu tiên khoản gắn lớp đó, không có thì khoản gần nhất) sau ưu đãi /
 * số buổi của khoản — lớp thu theo khóa hay theo tháng đều quy về một buổi, giảm học phí riêng (vd. giảm 600k/tháng) tự
 * phản ánh. HS chưa có khoản học phí → giá niêm yết một buổi của lớp. HS vào lớp sau / đã rời lớp / đang bảo lưu vào ngày
 * dạy không được tính buổi đó (cột "Số HS nghỉ tự trừ HP hoặc vào sau" của bảng mẫu).
 * Chỉ ca dạy lớp (chính khóa, dạy thay) được tính; kèm 1-1, chấm bài, workshop không có học phí lớp nên không tính.
 * % = đơn giá "% học phí" riêng hiệu lực tại ngày dạy (màn Đơn giá giáo viên), chưa có thì Tham số tính lương (mặc định 40%).
 */
class TeachingShareService
{
    /** Loại ca dạy được tính % học phí. */
    public const SHARED_TYPES = ['regular', 'sub'];

    /**
     * @param  Collection<int, TeacherTimesheet>  $timesheets  ca chấm công hợp lệ trong kỳ (nạp sẵn classModel)
     * @param  array<string, mixed>  $settings  PayrollPeriod::payrollSettings()
     * @return array{amount: float, sessions: int, outcomes: array<int, array<string, mixed>>, classes: list<array<string, mixed>>, skipped: int, default_percent: float}
     */
    public function forTimesheets(Collection $timesheets, array $settings): array
    {
        $defaultPercent = (float) ($settings['teaching_share_percent'] ?? 40);
        $shared = $timesheets->filter(fn (TeacherTimesheet $ts) => $ts->class_id && in_array($ts->type ?? 'regular', self::SHARED_TYPES, true))->values();
        $classIds = $shared->pluck('class_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        $members = $this->membersByClass($classIds);
        $studentIds = $members->flatten(1)->pluck('student_id')->unique()->values();
        $prices = $this->unitPrices($studentIds, $classIds);
        $paused = $this->deferrals($studentIds);
        $classes = ClassModel::with('course')->whereIn('id', $classIds)->get()->keyBy('id');

        $outcomes = [];
        $rows = [];
        foreach ($shared as $ts) {
            $classId = (int) $ts->class_id;
            $class = $classes->get($classId);
            $day = $ts->teaching_date?->toDateString();
            $percent = TeacherHourlyRate::tuitionPercentFor((int) $ts->user_id, $ts->teaching_date ?? now()) ?? $defaultPercent;
            $listed = $class ? SessionLedger::classUnitPrice($class) : 0.0;

            $present = $members->get($classId, collect())
                ->filter(fn (array $m) => ($m['from'] === null || $m['from'] <= $day) && ($m['until'] === null || $day < $m['until'])
                    && ! $paused->get($m['student_id'], collect())->contains(fn (array $d) => $day >= $d[0] && $day <= $d[1]))
                ->pluck('student_id')->unique()->values();
            $tuition = round($present->sum(fn (int $id) => $prices[$id.'-'.$classId] ?? $prices[$id.'-*'] ?? $listed), 2);
            $outcome = $ts->applyLateRules(round($tuition * $percent / 100, 2), $settings);
            $outcomes[$ts->id] = $outcome + ['percent' => $percent, 'students' => $present->count(), 'tuition' => $tuition];

            $row = $rows[$classId] ?? [
                'class_id' => $classId,
                'class' => $class ? trim(($class->code ? $class->code.' — ' : '').$class->name) : 'Lớp #'.$classId,
                'listed_price' => $listed,
                'sessions' => 0,
                'student_ids' => [],
                'session_students' => [],
                'student_sessions' => 0,
                'percents' => [],
                'tuition' => 0.0,
                'amount' => 0.0,
            ];
            if ($outcome['counted']) {
                $row['sessions']++;
                $row['tuition'] += $tuition;
                $row['session_students'][] = $present->all();
                $row['student_sessions'] += $present->count();
            }
            $row['student_ids'] = array_values(array_unique([...$row['student_ids'], ...$present->all()]));
            $row['percents'][] = $percent;
            $row['amount'] += $outcome['amount'];
            $rows[$classId] = $row;
        }

        $classRows = collect($rows)->map(function (array $row) {
            $total = count($row['student_ids']);
            // HS không thuộc lớp ở mọi buổi đã tính (vào lớp sau, rời lớp, bảo lưu) — cột "HS nghỉ / vào sau" của bảng mẫu.
            $always = $row['session_students'] === [] ? [] : array_intersect(...array_merge([$row['session_students'][0]], $row['session_students']));
            $percents = array_values(array_unique($row['percents']));

            return [
                'class_id' => $row['class_id'],
                'class' => $row['class'],
                'listed_price' => $row['listed_price'],
                // Học phí trung bình một HS một buổi (đã gồm giảm học phí riêng) — cột "Học phí" của bảng mẫu.
                'avg_price' => $row['student_sessions'] > 0 ? round($row['tuition'] / $row['student_sessions'], 0) : $row['listed_price'],
                'students' => $total,
                'partial_students' => max(0, $total - count($always)),
                'sessions' => $row['sessions'],
                'percent' => count($percents) === 1 ? $percents[0] : null,
                'tuition' => round($row['tuition'], 0),
                'amount' => round($row['amount'], 0),
            ];
        })->values()->all();

        return [
            'amount' => round(collect($classRows)->sum('amount'), 0),
            'sessions' => collect($classRows)->sum('sessions'),
            'outcomes' => $outcomes,
            'classes' => $classRows,
            'skipped' => $timesheets->count() - $shared->count(),
            'default_percent' => $defaultPercent,
        ];
    }

    /**
     * Thời gian từng HS thuộc lớp: [ngày xếp lớp, ngày rời lớp) như sổ buổi (SessionLedger). HS đang học chưa có lượt
     * xếp lớp (dữ liệu cũ) tính từ ngày tạo hồ sơ.
     *
     * @param  array<int>  $classIds
     * @return Collection<int, Collection<int, array{student_id: int, from: ?string, until: ?string}>>
     */
    private function membersByClass(array $classIds): Collection
    {
        if ($classIds === []) {
            return collect();
        }

        $enrollments = ClassEnrollment::query()->whereIn('class_id', $classIds)
            ->get(['student_id', 'class_id', 'enrolled_at', 'left_at', 'status', 'updated_at']);
        $periods = $enrollments->map(fn (ClassEnrollment $e) => [
            'class_id' => (int) $e->class_id,
            'student_id' => (int) $e->student_id,
            'from' => $e->enrolled_at?->toDateString(),
            'until' => $e->left_at?->toDateString()
                ?? ($e->status === Student::ENROLLMENT_DROPPED ? $e->updated_at?->toDateString() : null),
        ]);

        $withEnrollment = $enrollments->map(fn ($e) => $e->student_id.'-'.$e->class_id)->flip();
        Student::query()->whereIn('current_class_id', $classIds)->get(['id', 'current_class_id', 'created_at'])
            ->reject(fn (Student $s) => isset($withEnrollment[$s->id.'-'.$s->current_class_id]))
            ->each(fn (Student $s) => $periods->push([
                'class_id' => (int) $s->current_class_id,
                'student_id' => (int) $s->id,
                'from' => $s->created_at?->toDateString(),
                'until' => null,
            ]));

        return $periods->groupBy('class_id')->map(fn (Collection $rows) => $rows->values());
    }

    /**
     * Học phí theo buổi của từng HS: khóa "{hs}-{lớp}" = khoản gắn lớp đó (mới nhất), "{hs}-*" = khoản mới nhất bất kỳ.
     *
     * @param  Collection<int, int>  $studentIds
     * @param  array<int>  $classIds
     * @return array<string, float>
     */
    private function unitPrices(Collection $studentIds, array $classIds): array
    {
        if ($studentIds->isEmpty()) {
            return [];
        }

        $prices = [];
        StudentTuition::query()->whereIn('student_id', $studentIds)->orderBy('id')
            ->get(['id', 'student_id', 'class_id', 'total_amount', 'discount_amount', 'session_count'])
            ->filter(fn (StudentTuition $t) => (int) $t->session_count > 0)
            ->each(function (StudentTuition $t) use (&$prices, $classIds) {
                $price = $t->sessionUnitPrice();
                $prices[$t->student_id.'-*'] = $price;
                if ($t->class_id && in_array((int) $t->class_id, $classIds, true)) {
                    $prices[$t->student_id.'-'.$t->class_id] = $price;
                }
            });

        return $prices;
    }

    /**
     * Thời gian bảo lưu đã duyệt (không thu học phí → không tính buổi).
     *
     * @param  Collection<int, int>  $studentIds
     * @return Collection<int, Collection<int, array{0: string, 1: string}>>
     */
    private function deferrals(Collection $studentIds): Collection
    {
        if ($studentIds->isEmpty()) {
            return collect();
        }

        return TuitionRefundRequest::query()
            ->whereIn('student_id', $studentIds)
            ->where('type', TuitionRefundRequest::TYPE_DEFERRAL)
            ->where('status', 'approved')
            ->whereNotNull('defer_from')->whereNotNull('defer_to')
            ->get(['student_id', 'defer_from', 'defer_to'])
            ->groupBy('student_id')
            ->map(fn (Collection $rows) => $rows->map(fn ($d) => [$d->defer_from->toDateString(), $d->defer_to->toDateString()])->values());
    }
}
