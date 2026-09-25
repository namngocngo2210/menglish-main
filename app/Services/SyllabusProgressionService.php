<?php

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\BigTest;
use App\Models\BigTestResult;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tiến trình chặng của lớp (Q4 — BA chốt 25/09/2026).
 *
 * Quy tắc:
 *  1. Mỗi lớp chỉ có 1 chặng đang mở (SyllabusAssignment `in_progress`, DB chặn bằng UNIQUE open_class_id).
 *  2. Chặng đóng khi Big Test của chặng (big_tests.syllabus_stage_id + class_id) đã được duyệt VÀ gửi phụ huynh:
 *     mọi kết quả đều "Đã gửi PH", học viên vắng thi chỉ cần "Đã duyệt" (không có điểm để gửi);
 *     không còn kết quả nháp / chờ duyệt.
 *  3. Đóng chặng → tự mở chặng kế tiếp (position lớn hơn) của cùng giáo trình, cùng GV, và báo GV.
 *     Không còn chặng kế tiếp → đánh dấu lớp đã học xong giáo trình (curriculum_completed_at).
 *  4. Học thuật được mở/đóng tay (bắt buộc lý do) để xử lý ngoại lệ.
 */
class SyllabusProgressionService
{
    public function openAssignment(int|ClassModel $class): ?SyllabusAssignment
    {
        $classId = $class instanceof ClassModel ? $class->id : $class;

        return SyllabusAssignment::with(['stage', 'curriculum'])->open()->where('class_id', $classId)->first();
    }

    /**
     * Chặng lớp sẽ học tiếp trong giáo trình: chặng sau chặng đóng gần nhất của lớp (theo thứ tự),
     * chặng đầu tiên nếu lớp chưa học chặng nào; NULL khi lớp đã học xong chặng cuối.
     */
    public function nextStageFor(ClassModel $class, SyllabusCurriculum $curriculum): ?SyllabusStage
    {
        $lastClosed = SyllabusAssignment::with('stage')
            ->where('class_id', $class->id)
            ->where('curriculum_id', $curriculum->id)
            ->where('status', SyllabusAssignment::STATUS_CLOSED)
            ->whereNotNull('stage_id')
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->first();

        if (! $lastClosed?->stage) {
            return $curriculum->stages()->first();
        }

        return $lastClosed->stage->next();
    }

    /** Lớp đã học xong chặng cuối của giáo trình và không còn chặng đang mở. */
    public function curriculumFinished(ClassModel $class): bool
    {
        return ! SyllabusAssignment::open()->where('class_id', $class->id)->exists()
            && SyllabusAssignment::where('class_id', $class->id)->whereNotNull('curriculum_completed_at')->exists();
    }

    /**
     * Mở chặng cho lớp. Lớp đang có chặng mở thì từ chối (đóng chặng hiện tại trước, hoặc dùng switchTo()).
     */
    public function open(ClassModel $class, SyllabusStage $stage, ?int $teacherId, ?User $actor = null, ?string $reason = null, array $attributes = []): SyllabusAssignment
    {
        $teacherId = $teacherId ?: $class->teacher_id;
        if (! $teacherId) {
            throw ValidationException::withMessages(['user_id' => 'Lớp chưa có giáo viên phụ trách; vui lòng chọn giáo viên.']);
        }

        try {
            return DB::transaction(function () use ($class, $stage, $teacherId, $actor, $reason, $attributes) {
                $current = SyllabusAssignment::open()->where('class_id', $class->id)->lockForUpdate()->first();
                if ($current) {
                    throw ValidationException::withMessages([
                        'class_id' => "Lớp đang học chặng \"{$current->stage_name}\". Mỗi lớp chỉ mở 1 chặng — cần đóng chặng hiện tại trước.",
                    ]);
                }

                return SyllabusAssignment::create($attributes + [
                    'user_id' => $teacherId,
                    'curriculum_id' => $stage->curriculum_id,
                    'stage_id' => $stage->id,
                    'class_id' => $class->id,
                    'stage_name' => $stage->label,
                    'assigned_chapters' => $stage->rangeSummary(),
                    'progress_percent' => 0,
                    'status' => SyllabusAssignment::STATUS_OPEN,
                    'opened_at' => now(),
                    'opened_by' => $actor?->id,
                    'open_reason' => $reason,
                ]);
            });
        } catch (QueryException $e) {
            // Hai yêu cầu mở chặng cùng lúc: UNIQUE open_class_id chặn bản ghi thứ hai.
            throw ValidationException::withMessages(['class_id' => 'Lớp vừa được mở một chặng khác. Vui lòng tải lại trang.']);
        }
    }

    /**
     * Đóng chặng đang mở. $openNext = true thì tự mở chặng kế tiếp và báo GV.
     *
     * @return array{closed: SyllabusAssignment, next: ?SyllabusAssignment, finished: bool}
     */
    public function close(SyllabusAssignment $assignment, ?User $actor, string $reason, ?BigTest $bigTest = null, bool $openNext = true): array
    {
        return DB::transaction(function () use ($assignment, $actor, $reason, $bigTest, $openNext) {
            $assignment = SyllabusAssignment::with(['stage', 'classModel'])->lockForUpdate()->findOrFail($assignment->id);
            if (! $assignment->isOpen()) {
                throw ValidationException::withMessages(['status' => 'Chặng này đã đóng trước đó.']);
            }

            $next = $openNext ? $assignment->stage?->next() : null;
            $finished = $openNext && $assignment->stage && ! $next;

            $assignment->update([
                'status' => SyllabusAssignment::STATUS_CLOSED,
                'progress_percent' => 100,
                'closed_at' => now(),
                'closed_by' => $actor?->id,
                'close_reason' => $reason,
                'closed_by_big_test_id' => $bigTest?->id,
                'curriculum_completed_at' => $finished ? now() : null,
            ]);

            $nextAssignment = null;
            $class = $assignment->classModel;
            if ($next && $class) {
                $nextAssignment = $this->open(
                    $class,
                    $next,
                    $assignment->user_id,
                    $actor,
                    $bigTest
                        ? "Tự mở sau khi Big Test {$bigTest->code} của {$assignment->stage_name} được duyệt và gửi phụ huynh."
                        : "Mở tiếp sau khi đóng {$assignment->stage_name}: {$reason}"
                );
                $this->notifyTeachers($class, $nextAssignment->user_id, 'Lớp '.$class->name.' đã chuyển sang '.$nextAssignment->stage_name,
                    "{$assignment->stage_name} đã đóng".($bigTest ? " (Big Test {$bigTest->code} đã duyệt và gửi PH)" : '').'. Nội dung chặng mới đã mở trong màn Xem giáo trình.');
            } elseif ($finished && $class) {
                $this->notifyTeachers($class, $assignment->user_id, 'Lớp '.$class->name.' đã hoàn thành giáo trình',
                    "{$assignment->stage_name} là chặng cuối của giáo trình {$assignment->curriculum?->title}.");
            }

            return ['closed' => $assignment->fresh(), 'next' => $nextAssignment, 'finished' => $finished];
        });
    }

    /**
     * Học thuật chuyển lớp sang chặng khác (đóng chặng đang mở, không tự mở chặng kế tiếp, rồi mở chặng được chọn).
     */
    public function switchTo(ClassModel $class, SyllabusStage $stage, ?int $teacherId, User $actor, string $reason, array $attributes = []): SyllabusAssignment
    {
        return DB::transaction(function () use ($class, $stage, $teacherId, $actor, $reason, $attributes) {
            if ($current = $this->openAssignment($class)) {
                $this->close($current, $actor, 'Học thuật chuyển chặng: '.$reason, null, false);
            }

            return $this->open($class, $stage, $teacherId, $actor, $reason, $attributes);
        });
    }

    /** Big Test đã duyệt và gửi đủ: có kết quả, không còn kết quả chưa duyệt, mọi kết quả có điểm đã gửi PH. */
    public function bigTestCompleted(BigTest $test): bool
    {
        $results = BigTestResult::where('big_test_id', $test->id)->get(['status', 'is_absent']);

        return $results->isNotEmpty() && $results->every(
            fn ($r) => $r->status === 'sent' || ($r->is_absent && $r->status === 'approved')
        );
    }

    /**
     * Gọi sau mỗi lần duyệt kết quả / gửi PH. Big Test của chặng đang mở đã duyệt & gửi đủ → đóng chặng, mở chặng kế.
     *
     * @return array{closed: SyllabusAssignment, next: ?SyllabusAssignment, finished: bool}|null
     */
    public function syncBigTest(BigTest $test, ?User $actor = null): ?array
    {
        if (! $test->class_id || ! $test->syllabus_stage_id || ! $this->bigTestCompleted($test)) {
            return null;
        }

        if (! $test->results_completed_at) {
            $test->update(['results_completed_at' => now()]);
        }

        $assignment = SyllabusAssignment::open()
            ->where('class_id', $test->class_id)
            ->where('stage_id', $test->syllabus_stage_id)
            ->first();
        if (! $assignment) {
            return null;
        }

        return $this->close($assignment, $actor, "Big Test {$test->code} đã được duyệt và gửi kết quả cho phụ huynh.", $test);
    }

    /** Câu thông báo ngắn cho kết quả syncBigTest (dùng trong flash message). */
    public function describe(?array $outcome): string
    {
        if (! $outcome) {
            return '';
        }
        $closed = $outcome['closed'];

        return match (true) {
            $outcome['next'] !== null => " Đã đóng {$closed->stage_name} và tự mở {$outcome['next']->stage_name} cho lớp.",
            $outcome['finished'] => " Đã đóng {$closed->stage_name} — lớp đã hoàn thành giáo trình.",
            default => " Đã đóng {$closed->stage_name}.",
        };
    }

    /**
     * Vị trí dạy hiện tại của lớp trong chặng đang mở: số buổi đã dạy (buổi chính khóa/học bù không bị hủy,
     * trước hôm nay, từ ngày mở chặng) → buổi tiếp theo trong danh sách buổi của chặng.
     * Vượt quá số buổi của chặng = đang dùng buổi giãn tiến độ / ôn tập trước Big Test.
     *
     * @return array{lessons: Collection<int, SyllabusLesson>, taught: int, current: ?SyllabusLesson, over: int}
     */
    public function position(SyllabusAssignment $assignment): array
    {
        $lessons = $assignment->stage
            ? SyllabusLesson::with('unit')->whereIn('unit_id', $assignment->stage->units()->select('id'))->orderBy('session_no')->get()
            : collect();

        $taught = ClassSession::where('class_id', $assignment->class_id)
            ->whereIn('type', [ClassSession::TYPE_REGULAR, ClassSession::TYPE_MAKEUP])
            ->where('status', '!=', 'cancelled')
            ->whereDate('date', '<', today())
            ->when($assignment->opened_at, fn ($q) => $q->whereDate('date', '>=', $assignment->opened_at->toDateString()))
            ->count();

        return [
            'lessons' => $lessons,
            'taught' => $taught,
            'current' => $lessons->get($taught),
            'over' => max(0, $taught - $lessons->count()),
        ];
    }

    private function notifyTeachers(ClassModel $class, ?int $assignedTeacherId, string $title, string $message): void
    {
        $ids = collect([$assignedTeacherId, $class->teacher_id, $class->foreign_teacher_id])->filter()->unique();
        foreach ($ids as $userId) {
            AdminNotification::create([
                'user_id' => $userId,
                'type' => 'syllabus_stage',
                'title' => $title,
                'message' => $message,
                'data' => ['link' => route('syllabus.teacher-view', ['class' => $class->id])],
                'is_read' => false,
            ]);
        }
    }
}
