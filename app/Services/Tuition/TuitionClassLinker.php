<?php

namespace App\Services\Tuition;

use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Student;
use App\Models\StudentTuition;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;

/**
 * Gắn lớp học phí theo sổ buổi, không phụ thuộc số tiền hay trạng thái khoản học phí.
 * Khoản chưa dùng hết: tuitionUsage([$studentId], $asOf) < sessionSize(); buổi đã dùng được
 * phân bổ theo id khoản học phí tăng dần, khoản cũ dùng hết mới tới khoản sau.
 *
 * A. Khi xếp / liên kết lớp: chỉ gắn khoản chưa dùng hết có class_id NULL vào current_class_id
 * sau xếp lớp (chưa có lớp chính thì dùng lớp vừa xếp). Khoản đã có lớp hoặc đã dùng hết giữ nguyên.
 * B. Khi đổi lớp chính X → Y từ ngày D: chỉ chuyển khoản có lớp X hoặc NULL, chưa dùng hết tại
 * D lúc 00:00 (buổi trước D). Khoản đã dùng hết trước D giữ lớp cũ; khoản của lớp khác giữ nguyên.
 * C. Chỉ cập nhật class_id bằng query builder, không chạy saving, không đổi tiền, ưu đãi, phí,
 * trạng thái, công nợ, số buổi, nội dung chuyển khoản hay bất kỳ cột nào khác, kể cả updated_at.
 * Không tính lại lương; kỳ lương đã khóa giữ nguyên, kỳ nháp nhận lớp mới ở lần Tính lại thông thường.
 */
class TuitionClassLinker
{
    public function __construct(private SessionLedger $ledger) {}

    /** @return list<int> id khoản học phí đã đổi lớp */
    public function linkUnassigned(Student $student, ?ClassModel $placedClass = null): array
    {
        return $this->apply($this->planUnassigned($student, $placedClass));
    }

    /** @return list<int> id khoản học phí đã đổi lớp */
    public function moveOnTransfer(Student $student, int $fromClassId, int $toClassId, CarbonInterface|string $transferredOn): array
    {
        return $this->apply($this->planTransfer($student, $fromClassId, $toClassId, $transferredOn));
    }

    /** @return list<array{tuition_id: int, student_id: int, from_class_id: ?int, to_class_id: ?int, reason: string}> */
    public function planUnassigned(Student $student, ?ClassModel $placedClass = null): array
    {
        $targetId = $student->current_class_id ?: $placedClass?->id;
        if (! $targetId) {
            return [];
        }

        return $this->planFor($student, now(), fn (StudentTuition $tuition, bool $unfinished) => $tuition->class_id === null && $unfinished
            ? ['to_class_id' => (int) $targetId, 'reason' => 'Chưa dùng hết, gắn vào lớp chính']
            : null);
    }

    /** @return list<array{tuition_id: int, student_id: int, from_class_id: ?int, to_class_id: ?int, reason: string}> */
    public function planTransfer(Student $student, int $fromClassId, int $toClassId, CarbonInterface|string $transferredOn): array
    {
        if ($fromClassId === $toClassId) {
            return [];
        }

        $asOf = Carbon::parse($transferredOn)->startOfDay();

        return $this->planFor($student, $asOf, fn (StudentTuition $tuition, bool $unfinished) => $unfinished
            && ($tuition->class_id === null || (int) $tuition->class_id === $fromClassId)
                ? ['to_class_id' => $toClassId, 'reason' => 'Chưa dùng hết trước ngày chuyển lớp '.$asOf->toDateString()]
                : null);
    }

    /**
     * Bù dữ liệu: khoản NULL chưa dùng hết → lớp chính; khoản NULL đã dùng hết → lớp tại ngày tạo;
     * khoản chưa dùng hết của lớp đã chuyển đi, không còn lượt học hiệu lực → lớp chính hiện tại.
     * Học viên không có lớp chính giữ nguyên. to_class_id NULL biểu thị bỏ qua vì không rõ lịch sử.
     *
     * @return list<array{tuition_id: int, student_id: int, from_class_id: ?int, to_class_id: ?int, reason: string}>
     */
    public function planBackfill(Student $student): array
    {
        if (! $student->current_class_id) {
            return [];
        }

        $student->loadMissing('enrollments');
        $mainClassId = (int) $student->current_class_id;

        return $this->planFor($student, now(), function (StudentTuition $tuition, bool $unfinished) use ($student, $mainClassId) {
            if ($tuition->class_id === null) {
                if ($unfinished) {
                    return ['to_class_id' => $mainClassId, 'reason' => 'Chưa dùng hết, gắn vào lớp chính'];
                }

                $createdOn = $tuition->created_at?->toDateString();
                $enrollment = $createdOn === null ? null : $student->enrollments
                    ->filter(fn (ClassEnrollment $enrollment) => $enrollment->enrolled_at !== null
                        && $enrollment->enrolled_at->toDateString() <= $createdOn
                        && ($enrollment->left_at === null || $enrollment->left_at->toDateString() > $createdOn))
                    ->sortByDesc(fn (ClassEnrollment $enrollment) => [
                        (int) $enrollment->class_id === $mainClassId || $enrollment->status === Student::ENROLLMENT_TRANSFERRED,
                        in_array($enrollment->status, [...Student::ACTIVE_ENROLLMENT_STATUSES, Student::ENROLLMENT_TRANSFERRED], true),
                        $enrollment->enrolled_at->toDateString(),
                        $enrollment->id,
                    ])->first();

                return [
                    'to_class_id' => $enrollment ? (int) $enrollment->class_id : null,
                    'reason' => $enrollment ? 'Đã dùng hết, gắn lớp tại ngày tạo khoản' : 'Bỏ qua: không xác định được lớp tại ngày tạo khoản',
                ];
            }

            if (! $unfinished || (int) $tuition->class_id === $mainClassId) {
                return null;
            }

            $enrollments = $student->enrollments->where('class_id', $tuition->class_id);
            if ($enrollments->contains(fn (ClassEnrollment $enrollment) => in_array($enrollment->status, Student::ACTIVE_ENROLLMENT_STATUSES, true))
                || ! $enrollments->contains('status', Student::ENROLLMENT_TRANSFERRED)) {
                return null;
            }

            return ['to_class_id' => $mainClassId, 'reason' => 'Chưa dùng hết, đã chuyển khỏi lớp cũ'];
        });
    }

    /**
     * Lập kế hoạch chung cho chạy thật và xem trước; không ghi dữ liệu.
     *
     * @param  Closure(StudentTuition, bool): ?array{to_class_id: ?int, reason: string}  $destination
     * @return list<array{tuition_id: int, student_id: int, from_class_id: ?int, to_class_id: ?int, reason: string}>
     */
    private function planFor(Student $student, CarbonInterface $asOf, Closure $destination): array
    {
        $usage = $this->ledger->tuitionUsage([$student->id], $asOf);
        $tuitions = StudentTuition::query()->with('classModel.course', 'student.currentClass.course')
            ->where('student_id', $student->id)->orderBy('id')->get();
        $changes = [];
        foreach ($tuitions as $tuition) {
            $target = $destination($tuition, ($usage[$tuition->id] ?? 0) < $tuition->sessionSize());
            if ($target === null) {
                continue;
            }
            $changes[] = [
                'tuition_id' => (int) $tuition->id,
                'student_id' => (int) $student->id,
                'from_class_id' => $tuition->class_id === null ? null : (int) $tuition->class_id,
                ...$target,
            ];
        }

        return $changes;
    }

    /**
     * Chỉ ghi class_id; gọi trong giao dịch của luồng xếp lớp hoặc của từng nhóm học viên khi bù dữ liệu.
     *
     * @param  list<array{tuition_id: int, student_id: int, from_class_id: ?int, to_class_id: ?int, reason: string}>  $plan
     * @return list<int> id khoản học phí đã đổi lớp
     */
    public function apply(array $plan): array
    {
        $changes = collect($plan)->filter(fn (array $change) => $change['to_class_id'] !== null
            && $change['from_class_id'] !== $change['to_class_id']);
        foreach ($changes->groupBy('to_class_id') as $classId => $group) {
            // toBase tránh Eloquent tự thêm updated_at; không phát sinh sự kiện model.
            StudentTuition::whereIn('id', $group->pluck('tuition_id'))->toBase()->update(['class_id' => (int) $classId]);
        }

        return $changes->pluck('tuition_id')->all();
    }
}
