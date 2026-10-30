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
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Lương đứng lớp Học thuật = % × tổng giá niêm yết theo buổi của HS còn giữ ở lớp cuối kỳ, cộng qua từng ca hợp lệ.
 * Tính mọi ca chính khóa / dạy thay có lớp, kể cả Học thuật chưa bật cờ kiêm nhiệm; ca muộn / về sớm theo applyLateRules.
 * HS phải vào lớp không muộn hơn cuối kỳ, chưa rời / chuyển / nghỉ lớp tại ngày đó, không thôi học hoặc bảo lưu cuối kỳ.
 * Trên tập HS còn giữ, mỗi buổi chỉ tính HS đã vào lớp tại ngày dạy và không trong thời gian bảo lưu đã duyệt.
 * Giá một HS / buổi = total_amount ÷ session_count của khoản học phí gắn lớp mới nhất; thiếu thì khoản mới nhất có số
 * buổi hợp lệ, thiếu nữa thì giá niêm yết của lớp. Không trừ ưu đãi và không xét thanh toán / công nợ / trạng thái học phí.
 * % riêng hiệu lực tại ngày dạy (Đơn giá giáo viên) thay thế tham số mặc định teaching_share_percent (40%).
 */
class TeachingShareService
{
    /** Loại ca dạy được tính % học phí. */
    public const SHARED_TYPES = ['regular', 'sub'];

    /**
     * @param  Collection<int, TeacherTimesheet>  $timesheets  ca chấm công hợp lệ trong kỳ (nạp sẵn classModel)
     * @param  array<string, mixed>  $settings  PayrollPeriod::payrollSettings()
     * @param  CarbonInterface  $periodEnd  ngày cuối kỳ để xác định HS còn giữ
     * @return array{amount: float, sessions: int, outcomes: array<int, array<string, mixed>>, classes: list<array<string, mixed>>, skipped: int, default_percent: float}
     */
    public function forTimesheets(Collection $timesheets, array $settings, CarbonInterface $periodEnd): array
    {
        $defaultPercent = (float) ($settings['teaching_share_percent'] ?? 40);
        $shared = $timesheets->filter(fn (TeacherTimesheet $ts) => $ts->status === 'valid' && $ts->class_id
            && in_array($ts->type ?? 'regular', self::SHARED_TYPES, true))->values();
        $classIds = $shared->pluck('class_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $endDay = $periodEnd->toDateString();

        $members = $this->membersByClass($classIds, $periodEnd);
        $studentIds = $members->flatten(1)->pluck('student_id')->unique()->values();
        $paused = $this->deferrals($studentIds);
        // Bảo lưu cuối kỳ loại HS khỏi TẤT CẢ buổi trong kỳ, kể cả buổi trước ngày bắt đầu bảo lưu.
        $members = $members->map(fn (Collection $rows) => $rows->reject(fn (array $m) => $paused->get($m['student_id'], collect())->contains(fn (array $d) => $endDay >= $d[0] && $endDay <= $d[1]))->values());
        $prices = $this->unitPrices($studentIds, $classIds);
        $classes = ClassModel::with('course')->whereIn('id', $classIds)->get()->keyBy('id');

        $outcomes = [];
        $rows = [];
        foreach ($shared as $ts) {
            $classId = (int) $ts->class_id;
            $class = $classes->get($classId);
            $day = $ts->teaching_date->toDateString();
            $percent = TeacherHourlyRate::tuitionPercentFor((int) $ts->user_id, $ts->teaching_date) ?? $defaultPercent;
            $listed = $class ? SessionLedger::classUnitPrice($class) : 0.0;
            $kept = $members->get($classId, collect());
            $priceFor = fn (int $id) => $prices[$id.'-'.$classId] ?? $prices[$id.'-*'] ?? $listed;
            $present = $kept->filter(fn (array $m) => ($m['from'] === null || $m['from'] <= $day)
                && ! $paused->get($m['student_id'], collect())->contains(fn (array $d) => $day >= $d[0] && $day <= $d[1]))
                ->pluck('student_id')->unique()->values();
            $tuition = round($present->sum($priceFor), 2);
            $outcome = $ts->applyLateRules(round($tuition * $percent / 100, 2), $settings);
            $outcomes[$ts->id] = $outcome + ['percent' => $percent, 'students' => $present->count(), 'tuition' => $tuition];

            $row = $rows[$classId] ?? [
                'class_id' => $classId,
                'class' => $class ? trim(($class->code ? $class->code.' — ' : '').$class->name) : 'Lớp #'.$classId,
                'listed_price' => $listed,
                'students' => $kept->pluck('student_id')->unique()->count(),
                // Giá trị một buổi của toàn bộ HS giữ cuối kỳ; học phí thực tính còn theo ngày vào lớp / bảo lưu từng buổi.
                'session_value' => round($kept->pluck('student_id')->unique()->sum($priceFor), 2),
                'sessions' => 0,
                'percents' => [],
                'tuition' => 0.0,
                'amount' => 0.0,
            ];
            if ($outcome['counted']) {
                $row['sessions']++;
                $row['tuition'] += $tuition;
                $row['percents'][] = $percent;
            }
            $row['amount'] += $outcome['amount'];
            $rows[$classId] = $row;
        }

        $classRows = collect($rows)->map(function (array $row) {
            $percents = array_values(array_unique($row['percents']));
            unset($row['percents']);
            $row['percent'] = count($percents) === 1 ? $percents[0] : null;
            $row['tuition'] = round($row['tuition'], 0);
            $row['amount'] = round($row['amount'], 0);

            return $row;
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
     * Lượt xếp lớp còn hiệu lực cuối kỳ: [ngày xếp lớp, ngày rời lớp). Trạng thái thôi học / bảo lưu không được giữ.
     * Dữ liệu cũ chưa có lượt xếp lớp cho lớp hiện tại tính từ ngày tạo hồ sơ.
     *
     * @param  array<int>  $classIds
     * @return Collection<int, Collection<int, array{student_id: int, from: ?string, until: ?string}>>
     */
    private function membersByClass(array $classIds, CarbonInterface $periodEnd): Collection
    {
        if ($classIds === []) {
            return collect();
        }

        $endDay = $periodEnd->toDateString();
        $enrollments = ClassEnrollment::query()->whereIn('class_id', $classIds)
            ->get(['student_id', 'class_id', 'enrolled_at', 'left_at', 'status', 'updated_at']);
        $periods = $enrollments->map(fn (ClassEnrollment $e) => [
            'class_id' => (int) $e->class_id,
            'student_id' => (int) $e->student_id,
            'from' => $e->enrolled_at?->toDateString(),
            'until' => $e->left_at?->toDateString()
                ?? (in_array($e->status, [Student::ENROLLMENT_DROPPED, Student::ENROLLMENT_TRANSFERRED], true)
                    ? $e->updated_at?->toDateString() : null),
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

        $statuses = Student::query()->whereIn('id', $periods->pluck('student_id'))->pluck('status', 'id');
        // Khôi phục trạng thái tại cuối kỳ từ lần đổi trạng thái đầu tiên sau kỳ, tránh lấy việc nghỉ / học lại tháng sau.
        // Hồ sơ cũ không có nhật ký đổi trạng thái dùng trạng thái hiện có.
        if ($statuses->isNotEmpty()) {
            Activity::query()->where('subject_type', (new Student)->getMorphClass())->whereIn('subject_id', $statuses->keys())
                ->where('event', 'updated')->where('created_at', '>', $periodEnd->copy()->endOfDay())
                ->orderBy('created_at')->orderBy('id')->get(['subject_id', 'properties'])
                ->filter(fn (Activity $log) => data_get($log->properties, 'old.status') !== null)
                ->unique('subject_id')
                ->each(fn (Activity $log) => $statuses->put((int) $log->subject_id, data_get($log->properties, 'old.status')));
        }
        $eligibleIds = $statuses->reject(fn ($status) => in_array($status, [Student::STATUS_DROPPED, 'deferred'], true));

        return $periods->filter(fn (array $m) => isset($eligibleIds[$m['student_id']])
            && ($m['from'] === null || $m['from'] <= $endDay)
            && ($m['until'] === null || $endDay < $m['until']))
            ->groupBy('class_id')->map(fn (Collection $rows) => $rows->values());
    }

    /**
     * Giá niêm yết theo buổi của từng HS: khóa "{hs}-{lớp}" = khoản gắn lớp đó (mới nhất), "{hs}-*" = khoản mới nhất bất kỳ.
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
            ->get(['id', 'student_id', 'class_id', 'total_amount', 'session_count'])
            ->filter(fn (StudentTuition $t) => (int) $t->session_count > 0)
            ->each(function (StudentTuition $t) use (&$prices, $classIds) {
                $price = (float) $t->total_amount / (int) $t->session_count;
                $prices[$t->student_id.'-*'] = $price;
                if ($t->class_id && in_array((int) $t->class_id, $classIds, true)) {
                    $prices[$t->student_id.'-'.$t->class_id] = $price;
                }
            });

        return $prices;
    }

    /**
     * Thời gian bảo lưu đã duyệt, tính cả ngày bắt đầu và kết thúc (không tính HS trong thời gian này).
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
