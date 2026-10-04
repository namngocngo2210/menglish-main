<?php

namespace App\Services\Tuition;

use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sổ buổi học viên và số buổi cần thu khi lập phiếu (logic tính học phí mới, 04/10/2026):
 *
 * Sổ buổi (theo học viên, không theo lớp nên chuyển lớp / chi nhánh / sau hè mang nguyên số buổi tồn):
 *  + số buổi đã đóng: theo các khoản học phí, tính từ tiền đã gạch nợ khi phiếu thu được DUYỆT
 *    (đơn giá buổi = học phí sau ưu đãi khi chốt / số buổi của khoản; tiền "Thu khác" lúc chốt được tính trước).
 *  − 1 cho mỗi buổi lớp diễn ra trong thời gian học viên thuộc lớp (vắng vẫn trừ). Buổi đã hủy (nghỉ lễ, nghỉ hè)
 *    không trừ; thời gian bảo lưu đã duyệt không trừ.
 *
 * Lập phiếu thu:
 *  Buổi còn lại của khóa = số buổi của khóa (mặc định 24) − số buổi lớp hiện tại đã diễn ra.
 *  Buổi cần thu = MAX(Buổi còn lại − Buổi tồn, 0). Tồn nhiều hơn → thu 0, phần dư tự trừ vào khóa kế tiếp.
 */
class SessionLedger
{
    /** Số buổi một khóa khi khóa học chưa cấu hình số buổi. */
    public const DEFAULT_COURSE_SESSIONS = 24;

    /** Buổi được tính vào sổ buổi: buổi chính khóa + buổi học bù (dời từ ngày nghỉ). Buổi phụ đạo không tính. */
    public const COUNTED_TYPES = [ClassSession::TYPE_REGULAR, ClassSession::TYPE_MAKEUP];

    /** Số buổi của khóa (khóa của lớp), chưa cấu hình → 24. */
    public static function courseSessions(?ClassModel $class, ?Course $course = null): int
    {
        $course ??= $class?->course;
        $sessions = (int) ($course?->total_lessons ?? 0);

        return $sessions > 0 ? $sessions : self::DEFAULT_COURSE_SESSIONS;
    }

    /** Học phí niêm yết trọn khóa của lớp (học phí riêng của lớp, không có thì của khóa). */
    public static function classFee(ClassModel $class): float
    {
        return (float) ($class->tuition_fee > 0 ? $class->tuition_fee : ($class->course?->tuition_fee ?? 0));
    }

    /** Đơn giá một buổi theo giá niêm yết của lớp. */
    public static function classUnitPrice(ClassModel $class): float
    {
        return round(self::classFee($class) / self::courseSessions($class), 2);
    }

    /** Buổi đã diễn ra của lớp tới thời điểm $now (buổi không hủy, đã tới giờ bắt đầu). */
    public function heldSessions(ClassModel|int $class, ?CarbonInterface $now = null): int
    {
        $classId = $class instanceof ClassModel ? $class->id : $class;

        return $this->countedSessions([$classId], $now)->count();
    }

    /**
     * Số buổi đã diễn ra của nhiều lớp.
     *
     * @param  array<int>  $classIds
     * @return array<int, int> theo id lớp
     */
    public function heldCounts(array $classIds, ?CarbonInterface $now = null): array
    {
        return $this->countedSessions($classIds, $now)->countBy('class_id')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * Học phí khi xếp vào lớp: chỉ tính số buổi còn lại của khóa (vào giữa khóa không thu buổi đã diễn ra).
     *
     * @return array{sessions: int, course_sessions: int, fee: float}
     */
    public static function joinTuition(ClassModel $class, int $held): array
    {
        $size = self::courseSessions($class);
        $sessions = max(0, $size - $held);
        $full = self::classFee($class);

        return [
            'sessions' => $sessions,
            'course_sessions' => $size,
            'fee' => $sessions >= $size ? $full : round($full * $sessions / $size),
        ];
    }

    /**
     * Tiến độ khóa của lớp: số buổi của khóa, đã diễn ra, còn lại.
     *
     * @return array{size: int, held: int, remaining: int}
     */
    public function classProgress(ClassModel $class, ?CarbonInterface $now = null): array
    {
        $size = self::courseSessions($class);
        $held = $this->heldSessions($class, $now);

        return ['size' => $size, 'held' => $held, 'remaining' => max(0, $size - $held)];
    }

    /**
     * @return array{paid: int, used: int, balance: int}
     */
    public function forStudent(Student|int $student, ?CarbonInterface $now = null): array
    {
        $id = $student instanceof Student ? $student->id : $student;

        return $this->forStudents([$id], $now)[$id];
    }

    /**
     * Sổ buổi của nhiều học viên (gộp truy vấn).
     *
     * @param  iterable<int>  $studentIds
     * @return array<int, array{paid: int, used: int, balance: int}>
     */
    public function forStudents(iterable $studentIds, ?CarbonInterface $now = null): array
    {
        $ids = collect($studentIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $paid = StudentTuition::query()->whereIn('student_id', $ids)->get()
            ->groupBy('student_id')
            ->map(fn (Collection $tuitions) => (int) $tuitions->sum(fn (StudentTuition $t) => $t->paidSessions()));

        $used = $this->usedSessions($ids, $now);

        return $ids->mapWithKeys(function (int $id) use ($paid, $used) {
            $p = (int) ($paid[$id] ?? 0);
            $u = (int) ($used[$id] ?? 0);

            return [$id => ['paid' => $p, 'used' => $u, 'balance' => $p - $u]];
        })->all();
    }

    /**
     * Số buổi đã trừ của từng học viên: buổi của các lớp học viên thuộc về (từ ngày xếp lớp tới trước ngày rời lớp),
     * bỏ các buổi nằm trong thời gian bảo lưu đã duyệt.
     *
     * @param  Collection<int, int>  $ids
     * @return array<int, int>
     */
    private function usedSessions(Collection $ids, ?CarbonInterface $now = null): array
    {
        $periods = $this->membershipPeriods($ids);
        if ($periods->isEmpty()) {
            return [];
        }

        $sessions = $this->countedSessions($periods->pluck('class_id')->unique()->values()->all(), $now)->groupBy('class_id');
        $deferrals = TuitionRefundRequest::query()
            ->whereIn('student_id', $ids)
            ->where('type', TuitionRefundRequest::TYPE_DEFERRAL)
            ->where('status', 'approved')
            ->whereNotNull('defer_from')->whereNotNull('defer_to')
            ->get(['student_id', 'defer_from', 'defer_to'])
            ->groupBy('student_id');

        $result = [];
        foreach ($periods->groupBy('student_id') as $studentId => $studentPeriods) {
            $paused = $deferrals->get($studentId, collect());
            $counted = [];
            foreach ($studentPeriods as $period) {
                foreach ($sessions->get($period['class_id'], collect()) as $session) {
                    $day = $session->date->toDateString();
                    if (($period['from'] !== null && $day < $period['from']) || ($period['until'] !== null && $day >= $period['until'])) {
                        continue;
                    }
                    if ($paused->contains(fn ($d) => $day >= $d->defer_from->toDateString() && $day <= $d->defer_to->toDateString())) {
                        continue;
                    }
                    $counted[$session->id] = true;
                }
            }
            $result[(int) $studentId] = count($counted);
        }

        return $result;
    }

    /**
     * Thời gian học viên thuộc mỗi lớp: [ngày xếp lớp, ngày rời lớp). Lớp đang học chưa có lượt xếp lớp (dữ liệu cũ)
     * tính từ ngày tạo hồ sơ học viên.
     *
     * @param  Collection<int, int>  $ids
     * @return Collection<int, array{student_id: int, class_id: int, from: ?string, until: ?string}>
     */
    private function membershipPeriods(Collection $ids): Collection
    {
        $enrollments = ClassEnrollment::query()
            ->whereIn('student_id', $ids)
            ->get(['student_id', 'class_id', 'enrolled_at', 'left_at', 'status', 'updated_at']);

        $periods = $enrollments->map(fn (ClassEnrollment $e) => [
            'student_id' => (int) $e->student_id,
            'class_id' => (int) $e->class_id,
            'from' => $e->enrolled_at?->toDateString(),
            'until' => $e->left_at?->toDateString()
                ?? ($e->status === Student::ENROLLMENT_DROPPED ? $e->updated_at?->toDateString() : null),
        ]);

        $withEnrollment = $enrollments->map(fn ($e) => $e->student_id.'-'.$e->class_id)->flip();
        Student::query()->whereIn('id', $ids)->whereNotNull('current_class_id')->get(['id', 'current_class_id', 'created_at'])
            ->reject(fn (Student $s) => isset($withEnrollment[$s->id.'-'.$s->current_class_id]))
            ->each(fn (Student $s) => $periods->push([
                'student_id' => (int) $s->id,
                'class_id' => (int) $s->current_class_id,
                'from' => $s->created_at?->toDateString(),
                'until' => null,
            ]));

        return $periods->values();
    }

    /**
     * Buổi của các lớp được tính vào sổ buổi và đã diễn ra tới $now.
     *
     * @param  array<int>  $classIds
     * @return Collection<int, ClassSession>
     */
    private function countedSessions(array $classIds, ?CarbonInterface $now = null): Collection
    {
        if ($classIds === []) {
            return collect();
        }

        $now = Carbon::instance($now ?? now());
        $today = $now->toDateString();

        return ClassSession::query()
            ->whereIn('class_id', $classIds)
            ->whereIn('type', self::COUNTED_TYPES)
            ->where('status', '!=', 'cancelled')
            ->whereDate('date', '<=', $today)
            ->get(['id', 'class_id', 'date', 'start_time'])
            ->filter(fn (ClassSession $s) => $s->date->toDateString() < $today || $s->startsAt()->lte($now))
            ->values();
    }

    /**
     * Gợi ý lập phiếu thu cho học viên theo sổ buổi.
     * mode: contract = thu tiếp khoản học phí đang mở (đơn giá theo khoản đó); new = khoản học phí mới cho lớp đang học
     * (khóa kế tiếp, đơn giá niêm yết, tạo khi phiếu được duyệt); none = chưa có lớp và chưa có khoản học phí.
     *
     * @param  array{paid: int, used: int, balance: int}|null  $ledger
     * @return array<string, mixed>
     */
    public function quote(Student $student, ?StudentTuition $openTuition = null, ?array $ledger = null, ?array $progress = null): array
    {
        $ledger ??= $this->forStudent($student);
        $class = $student->currentClass ?? $openTuition?->classModel;

        if ($class) {
            $progress ??= $this->classProgress($class);
        } else {
            // Chưa xếp lớp: chưa có buổi nào diễn ra, khóa = số buổi của khoản học phí.
            $size = (int) ($openTuition?->session_count ?: self::DEFAULT_COURSE_SESSIONS);
            $progress = ['size' => $size, 'held' => 0, 'remaining' => $size];
        }

        $needed = max(0, $progress['remaining'] - $ledger['balance']);
        $carryOver = max(0, $ledger['balance'] - $progress['remaining']);

        if ($openTuition && (float) $openTuition->debt_amount > 0) {
            $mode = 'contract';
            $unitPrice = $openTuition->sessionUnitPrice();
            $maxSessions = $openTuition->remainingSessions();
            $feeDue = $openTuition->feeRemaining();
            $tuitionRemaining = $openTuition->tuitionRemaining();
        } elseif ($class) {
            $mode = 'new';
            $unitPrice = self::classUnitPrice($class);
            $maxSessions = $needed;
            $feeDue = 0.0;
            $tuitionRemaining = null;
        } else {
            $mode = 'none';
            $unitPrice = 0.0;
            $maxSessions = 0;
            $feeDue = 0.0;
            $tuitionRemaining = null;
        }

        return [
            'paid' => $ledger['paid'],
            'used' => $ledger['used'],
            'balance' => $ledger['balance'],
            'class_name' => $class?->name,
            'course_sessions' => $progress['size'],
            'held' => $progress['held'],
            'remaining' => $progress['remaining'],
            'needed' => $needed,
            'carry_over' => $carryOver,
            'mode' => $mode,
            'tuition_id' => $mode === 'contract' ? $openTuition->id : null,
            'unit_price' => $unitPrice,
            'max_sessions' => $maxSessions,
            'contract_remaining_sessions' => $mode === 'contract' ? $maxSessions : null,
            'tuition_remaining' => $tuitionRemaining,
            'fee_due' => $feeDue,
            'suggested' => min($needed, $maxSessions),
        ];
    }

    /**
     * Gợi ý lập phiếu cho nhiều cặp (học viên, khoản học phí đang mở) — gộp truy vấn sổ buổi và tiến độ lớp.
     *
     * @param  array<int|string, array{0: Student, 1: ?StudentTuition}>  $pairs
     * @return array<int|string, array<string, mixed>> cùng khóa với $pairs
     */
    public function quoteMany(array $pairs, ?CarbonInterface $now = null): array
    {
        $pairs = collect($pairs);
        if ($pairs->isEmpty()) {
            return [];
        }

        $ledgers = $this->forStudents($pairs->map(fn (array $pair) => $pair[0]->id), $now);
        $classOf = fn (array $pair) => $pair[0]->currentClass ?? $pair[1]?->classModel;
        $classes = $pairs->map($classOf)->filter()->unique('id');
        $held = $this->countedSessions($classes->pluck('id')->all(), $now)->countBy('class_id');

        return $pairs->map(function (array $pair) use ($ledgers, $held, $classOf) {
            [$student, $tuition] = $pair;
            $class = $classOf($pair);
            $progress = null;
            if ($class) {
                $size = self::courseSessions($class);
                $h = (int) ($held[$class->id] ?? 0);
                $progress = ['size' => $size, 'held' => $h, 'remaining' => max(0, $size - $h)];
            }

            return $this->quote($student, $tuition, $ledgers[$student->id], $progress);
        })->all();
    }

    /**
     * Khoản học phí còn nợ mới nhất của từng học viên.
     *
     * @param  iterable<int>  $studentIds
     * @return Collection<int, StudentTuition> theo id học viên
     */
    public static function openTuitionsFor(iterable $studentIds): Collection
    {
        return StudentTuition::query()
            ->with('classModel.course')
            ->whereIn('student_id', collect($studentIds)->filter()->unique()->values())
            ->where('debt_amount', '>', 0)
            ->orderBy('id')
            ->get()
            ->keyBy('student_id');
    }

    /** Khoản học phí còn nợ mới nhất của học viên (phiếu thu theo buổi thu tiếp vào khoản này). */
    public static function openTuitionFor(Student $student): ?StudentTuition
    {
        return StudentTuition::query()
            ->where('student_id', $student->id)
            ->where('debt_amount', '>', 0)
            ->latest('id')
            ->first();
    }

    /**
     * Tính tiền phiếu thu theo buổi — cùng công thức với resources/js/lib/sessionReceipt.js:
     *  tong_truoc_giam = tiền buổi + học liệu + thi + khác; tong_phai_thu = tong_truoc_giam − giảm;
     *  so_tien = tong_phai_thu + phụ thu.
     * Tiền buổi: số buổi × đơn giá; thu hết số buổi còn lại của khoản học phí → đúng số học phí còn nợ.
     * Học liệu còn nợ lúc chốt (fee_due) thu kèm khi thu buổi của khoản đó. Giảm trừ chỉ tính trên tiền buổi.
     * Phần cấn công nợ khoản học phí = tiền buổi + học liệu còn nợ − giảm; hàng hóa mới, thi, khác, phụ thu thu đủ
     * ngay (cột surcharge_amount, không cấn nợ, không tính hoa hồng).
     *
     * @param  array<string, mixed>  $quote
     * @return array{session_count: int, session_value: float, fee_due: float, material_fee: float, exam_fee: float, other_fee: float, subtotal: float, discount: float, total_due: float, extra: float, amount: float, tuition_amount: float, surcharge_amount: float}
     */
    public static function calculate(array $quote, int $sessions, float $itemsTotal, float $examFee, float $otherFee, float $extra, float $discount): array
    {
        $sessions = max(0, $sessions);
        $contract = ($quote['mode'] ?? null) === 'contract';
        $sessionValue = $sessions > 0 && $contract && $quote['contract_remaining_sessions'] !== null && $sessions >= (int) $quote['contract_remaining_sessions']
            ? round((float) $quote['tuition_remaining'], 2)
            : round($sessions * (float) ($quote['unit_price'] ?? 0));
        $feeDue = $sessions > 0 && $contract ? round((float) ($quote['fee_due'] ?? 0), 2) : 0.0;
        $examFee = max(0.0, round($examFee, 2));
        $otherFee = max(0.0, round($otherFee, 2));
        $itemsTotal = max(0.0, round($itemsTotal, 2));
        $extra = max(0.0, round($extra, 2));
        $discount = min(max(0.0, round($discount, 2)), $sessionValue);

        $material = $itemsTotal + $feeDue;
        $subtotal = $sessionValue + $material + $examFee + $otherFee;
        $totalDue = $subtotal - $discount;

        return [
            'session_count' => $sessions,
            'session_value' => $sessionValue,
            'fee_due' => $feeDue,
            'material_fee' => $material,
            'exam_fee' => $examFee,
            'other_fee' => $otherFee,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total_due' => $totalDue,
            'extra' => $extra,
            'amount' => $totalDue + $extra,
            'tuition_amount' => $sessionValue + $feeDue - $discount,
            'surcharge_amount' => $itemsTotal + $examFee + $otherFee + $extra,
        ];
    }

    /**
     * Phiếu thu theo buổi của học viên chưa có khoản học phí đang mở (khóa kế tiếp): khi phiếu được duyệt, tạo khoản
     * học phí cho lớp đang học đúng bằng số buổi của phiếu × đơn giá của phiếu, rồi gắn phiếu vào khoản đó.
     */
    public static function createCourseTuition(TuitionReceipt $receipt): ?StudentTuition
    {
        $student = $receipt->student()->with('currentClass.course')->first();
        if (! $student || (int) $receipt->session_count <= 0) {
            return null;
        }

        $class = $student->currentClass;
        $value = round((float) $receipt->tuitionPortion() + (float) $receipt->discount_amount, 2);

        $tuition = StudentTuition::create([
            'student_id' => $student->id,
            'class_id' => $class?->id,
            'branch_id' => $class?->branch_id ?? $student->branch_id,
            'total_amount' => $value,
            'session_count' => (int) $receipt->session_count,
            'discount_amount' => 0,
            'other_fees' => 0,
            'prepaid_amount' => 0,
            'final_amount' => $value,
            'paid_amount' => 0,
            'debt_amount' => $value,
            'due_date' => now()->toDateString(),
            'status' => 'unpaid',
            'notes' => "Khoản học phí khóa kế tiếp ({$receipt->session_count} buổi) tạo từ phiếu thu {$receipt->receipt_number}.",
        ]);

        $receipt->student_tuition_id = $tuition->id;

        return $tuition;
    }
}
