<?php

namespace App\Services\Practice;

use App\Models\PracticeAttempt;
use App\Models\PracticeAttemptItem;
use App\Models\PracticeBlueprint;
use App\Models\QuestionBankQuestion;
use App\Models\Student;
use App\Models\User;
use App\Support\ExamViolations;
use App\Support\Practice\PracticeCatalog;
use App\Support\Practice\SchoolGrade;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Vòng đời một lượt luyện tập: bắt đầu / làm tiếp, lưu đáp án, ghi vi phạm, chấm (idempotent), tự nộp khi quá hạn
 * (docs/luyen-tap-ngan-hang-cau-hoi.md §6.2–6.4).
 */
class PracticeAttemptService
{
    /** Server còn nhận đáp án thêm chừng này giây sau deadline_at (bù trễ mạng / debounce). */
    public const ANSWER_GRACE_SECONDS = 30;

    /** Cửa sổ chống lặp câu: số lượt gần nhất cùng khối × kỳ. */
    private const RECENT_ATTEMPTS = 3;

    private const CHOICES = ['A', 'B', 'C', 'D'];

    public function __construct(private PracticeTestGenerator $generator) {}

    /**
     * Làm tiếp lượt đang làm (chưa quá hạn) của học viên ở kỳ này, hoặc sinh đề mới; lượt đã quá hạn được chốt trước.
     *
     * @throws PracticeException
     */
    public function start(Student $student, User $user, string $term): PracticeAttempt
    {
        if (! array_key_exists($term, PracticeCatalog::TERMS)) {
            throw new PracticeException('Kỳ luyện tập không hợp lệ.');
        }

        // Lỗi được trả ra rồi mới ném sau commit, để việc chốt lượt quá hạn không bị rollback.
        $result = DB::transaction(function () use ($student, $user, $term) {
            // khóa hàng học viên: hai yêu cầu bắt đầu đồng thời không tạo hai lượt đang làm
            Student::query()->whereKey($student->id)->lockForUpdate()->first(['id']);

            $current = PracticeAttempt::query()
                ->where('student_id', $student->id)
                ->where('term', $term)
                ->where('status', PracticeAttempt::IN_PROGRESS)
                ->latest('id')
                ->first();

            if ($current !== null) {
                if (now()->lessThanOrEqualTo($current->deadline_at)) {
                    return $current;
                }
                $this->finalize($current, 'timeout');
            }

            $grade = SchoolGrade::forStudent($student);
            if ($grade === null) {
                return new PracticeException('Chưa xác định khối của học viên — cần cập nhật ngày sinh.');
            }

            $blueprint = PracticeBlueprint::forGradeTerm($grade, $term);
            $questions = in_array($grade, PracticeCatalog::GRADES, true)
                ? $this->generator->generate($blueprint, $this->recentQuestionIds($student, $grade, $term))
                : collect();

            if ($questions->isEmpty()) {
                return new PracticeException('Chưa có đề cho khối '.$grade.' – '.PracticeCatalog::TERMS[$term].'.');
            }

            return $this->createAttempt($student, $user, $blueprint, $questions);
        });

        if ($result instanceof PracticeException) {
            throw $result;
        }

        return $result;
    }

    /**
     * Lưu (hoặc xóa khi $choice null) đáp án của câu ở vị trí $position.
     *
     * @throws PracticeException
     */
    public function saveAnswer(PracticeAttempt $attempt, int $position, ?string $choice): void
    {
        if ($choice !== null && ! in_array($choice, self::CHOICES, true)) {
            throw new PracticeException('Đáp án không hợp lệ.');
        }

        DB::transaction(function () use ($attempt, $position, $choice) {
            $locked = PracticeAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if ($locked->status !== PracticeAttempt::IN_PROGRESS) {
                throw new PracticeException('Bài làm đã được nộp.');
            }
            if (now()->greaterThan($locked->deadline_at->copy()->addSeconds(self::ANSWER_GRACE_SECONDS))) {
                throw new PracticeException('Đã hết thời gian làm bài.');
            }

            $item = PracticeAttemptItem::query()
                ->where('attempt_id', $locked->id)
                ->where('position', $position)
                ->first();
            if ($item === null) {
                throw new PracticeException('Câu hỏi không tồn tại trong bài làm.');
            }

            $item->update(['chosen' => $choice]);
        });
    }

    /** Ghi một lần rời bài; đủ ExamViolations::MAX thì tự nộp. Bỏ qua khi bài đã nộp hoặc loại vi phạm không hợp lệ. */
    public function recordViolation(PracticeAttempt $attempt, string $type): PracticeAttempt
    {
        return DB::transaction(function () use ($attempt, $type) {
            $locked = PracticeAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if ($locked->status !== PracticeAttempt::IN_PROGRESS || ! ExamViolations::isValidType($type)) {
                return $locked;
            }

            $log = $locked->violation_log ?? [];
            $log[] = ['type' => $type, 'at' => now()->toIso8601String()];
            $locked->violation_log = $log;
            $locked->violation_count++;
            $locked->save();

            if ($locked->violation_count >= ExamViolations::MAX) {
                return $this->finalize($locked, 'violations');
            }

            return $locked;
        });
    }

    /**
     * Chấm và chốt bài. Idempotent: bài đã nộp được trả về nguyên trạng (điểm, giờ nộp không đổi).
     *
     * @param  string|null  $reason  null = học viên tự nộp; 'timeout' | 'violations' = hệ thống tự nộp.
     */
    public function finalize(PracticeAttempt $attempt, ?string $reason = null): PracticeAttempt
    {
        return DB::transaction(function () use ($attempt, $reason) {
            $locked = PracticeAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($locked->status !== PracticeAttempt::IN_PROGRESS) {
                return $locked;
            }

            $items = $locked->items()->get(['id', 'skill', 'snapshot', 'chosen']);
            $correctIds = [];
            $wrongIds = [];
            $skills = [];
            foreach ($items as $item) {
                // câu bỏ trống tính là sai
                $isCorrect = $item->chosen !== null && $item->chosen === ($item->snapshot['answer'] ?? null);
                if ($isCorrect) {
                    $correctIds[] = $item->id;
                } else {
                    $wrongIds[] = $item->id;
                }
                $skills[$item->skill] ??= ['correct' => 0, 'total' => 0];
                $skills[$item->skill]['total']++;
                $skills[$item->skill]['correct'] += $isCorrect ? 1 : 0;
            }
            uksort($skills, fn ($a, $b) => PracticeCatalog::skillOrder($a) <=> PracticeCatalog::skillOrder($b));

            if ($correctIds !== []) {
                PracticeAttemptItem::query()->whereIn('id', $correctIds)->update(['is_correct' => true]);
            }
            if ($wrongIds !== []) {
                PracticeAttemptItem::query()->whereIn('id', $wrongIds)->update(['is_correct' => false]);
            }

            $total = $items->count();
            $correct = count($correctIds);
            $locked->fill([
                'status' => PracticeAttempt::SUBMITTED,
                'submitted_at' => now(),
                'auto_submitted' => $reason !== null,
                'submit_reason' => $reason,
                'total' => $total,
                'correct' => $correct,
                'score' => $total > 0 ? round($correct / $total * 10, 2) : 0,
                'skill_scores' => $skills,
            ])->save();

            return $locked;
        });
    }

    /** Tự nộp (timeout) các lượt đang làm đã quá deadline_at + thời gian ân hạn; trả về số lượt đã chốt. */
    public function closeExpired(): int
    {
        $count = 0;
        PracticeAttempt::query()
            ->where('status', PracticeAttempt::IN_PROGRESS)
            ->where('deadline_at', '<', now()->subSeconds(self::ANSWER_GRACE_SECONDS))
            ->select('id')
            ->chunkById(100, function ($attempts) use (&$count) {
                foreach ($attempts as $attempt) {
                    // wasChanged: false khi lượt đã được nộp ở nơi khác trong lúc chạy
                    if ($this->finalize($attempt, 'timeout')->wasChanged('status')) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * Câu hỏi trong các lượt gần nhất của học viên cùng khối × kỳ (một truy vấn; bảng dẫn xuất thay cho
     * `IN (… LIMIT)` mà MariaDB không hỗ trợ).
     *
     * @return list<int>
     */
    private function recentQuestionIds(Student $student, int $grade, string $term): array
    {
        $recent = PracticeAttempt::query()
            ->select('id')
            ->where('student_id', $student->id)
            ->where('grade', $grade)
            ->where('term', $term)
            ->orderByDesc('id')
            ->limit(self::RECENT_ATTEMPTS);

        return PracticeAttemptItem::query()
            ->joinSub($recent, 'recent', 'recent.id', '=', 'practice_attempt_items.attempt_id')
            ->whereNotNull('practice_attempt_items.question_id')
            ->distinct()
            ->pluck('practice_attempt_items.question_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @param  Collection<int, QuestionBankQuestion>  $questions */
    private function createAttempt(Student $student, User $user, PracticeBlueprint $blueprint, Collection $questions): PracticeAttempt
    {
        $now = now();
        $attempt = PracticeAttempt::create([
            'student_id' => $student->id,
            'user_id' => $user->id,
            'grade' => $blueprint->grade,
            'term' => $blueprint->term,
            'status' => PracticeAttempt::IN_PROGRESS,
            'started_at' => $now,
            'deadline_at' => $now->copy()->addMinutes($blueprint->duration_minutes),
            'total' => $questions->count(),
        ]);

        $rows = $questions->values()->map(fn (QuestionBankQuestion $q, int $i) => [
            'attempt_id' => $attempt->id,
            'question_id' => $q->id,
            'position' => $i + 1,
            'skill' => $q->skill,
            'type' => $q->type,
            'snapshot' => json_encode($this->snapshot($q), JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();
        PracticeAttemptItem::query()->insert($rows);

        return $attempt;
    }

    /**
     * Bản chụp câu hỏi lúc sinh đề: sửa / ẩn câu trong ngân hàng sau đó không làm đổi bài đã làm. Ảnh lưu đường dẫn
     * trong kho (không phải URL chứa APP_URL / tên miền R2); PracticeAttemptProps dựng URL khi trả props.
     */
    private function snapshot(QuestionBankQuestion $q): array
    {
        $passage = $q->passage;

        return [
            'instruction' => $q->instruction ?? $passage?->instruction,
            'passage' => $passage ? [
                'content' => $passage->content,
                'image_path' => $passage->image_path,
            ] : null,
            'image_path' => $q->image_path,
            'stem' => $q->stem,
            'options' => $q->options,
            'answer' => $q->answer,
            'knowledge' => $q->knowledge,
        ];
    }
}
