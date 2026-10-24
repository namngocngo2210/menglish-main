<?php

namespace Tests\Feature;

use App\Models\PracticeAttempt;
use App\Models\PracticeAttemptItem;
use App\Models\QuestionBankPassage;
use App\Models\QuestionBankQuestion;
use App\Models\Student;
use App\Models\User;
use App\Services\Practice\PracticeAttemptProps;
use App\Services\Practice\PracticeAttemptService;
use App\Services\Practice\PracticeException;
use App\Support\Practice\PracticeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PracticeAttemptServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Student $student;

    private PracticeAttemptService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // năm học 2026–2027: sinh 2014 → khối 7
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(9, 0));

        $this->user = User::factory()->create();
        $this->student = $this->makeStudent('2014-03-10', $this->user->id);
        $this->service = app(PracticeAttemptService::class);
    }

    private function makeStudent(?string $dob, ?int $userId = null): Student
    {
        static $n = 0;
        $n++;

        return Student::create([
            'user_id' => $userId,
            'name' => "Học viên $n",
            'code' => "HV-PR-$n",
            'phone' => '09000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'status' => 'active',
            'dob' => $dob,
        ]);
    }

    /** Đủ câu đã duyệt cho khung mặc định (40 câu) × $copies; dòng "Điền từ – đoạn văn" là ngữ liệu 5 câu. */
    private function seedBank(int $grade = 7, string $term = 'gk1', int $copies = 1): void
    {
        static $n = 0;
        for ($copy = 0; $copy < $copies; $copy++) {
            foreach (PracticeCatalog::DEFAULT_ITEMS as $row) {
                $passageId = null;
                if ($row['type'] === 'Điền từ – đoạn văn') {
                    $passageId = QuestionBankPassage::create([
                        'grade' => $grade, 'term' => $term, 'skill' => $row['skill'], 'type' => $row['type'],
                        'instruction' => 'Đọc đoạn văn', 'content' => 'Passage text', 'image_path' => 'question-bank/p.png',
                        'source_key' => 'p-'.(++$n),
                    ])->id;
                }
                for ($i = 1; $i <= $row['count']; $i++) {
                    $n++;
                    QuestionBankQuestion::create([
                        'passage_id' => $passageId, 'position' => $i,
                        'grade' => $grade, 'term' => $term, 'skill' => $row['skill'], 'type' => $row['type'],
                        'instruction' => $passageId ? null : 'Chọn đáp án đúng', 'knowledge' => 'Thì hiện tại',
                        'stem' => "Q$n", 'options' => ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'],
                        'answer' => 'B', 'status' => 'approved', 'source_key' => "q-$n",
                    ]);
                }
            }
        }
    }

    private function exceptionMessage(callable $fn): ?string
    {
        try {
            $fn();
        } catch (PracticeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function test_start_creates_attempt_with_snapshot_and_deadline(): void
    {
        $this->seedBank();

        $attempt = $this->service->start($this->student, $this->user, 'gk1');

        $this->assertSame(PracticeAttempt::IN_PROGRESS, $attempt->status);
        $this->assertSame(7, $attempt->grade);
        $this->assertSame('gk1', $attempt->term);
        $this->assertSame($this->user->id, $attempt->user_id);
        $this->assertTrue($attempt->started_at->equalTo(now()));
        $this->assertTrue($attempt->deadline_at->equalTo($attempt->started_at->copy()->addMinutes(45)));

        $items = $attempt->items()->get();
        $this->assertCount(40, $items);
        $this->assertSame(range(1, 40), $items->pluck('position')->all());
        $this->assertSame('Ngữ âm', $items->first()->skill);

        $plain = $items->first()->snapshot;
        $this->assertSame(['instruction', 'passage', 'image_path', 'stem', 'options', 'answer', 'knowledge'], array_keys($plain));
        $this->assertNull($plain['passage']);
        $this->assertSame('Chọn đáp án đúng', $plain['instruction']);
        $this->assertSame('B', $plain['answer']);
        $this->assertSame(['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'], $plain['options']);

        $withPassage = $items->firstWhere('type', 'Điền từ – đoạn văn')->snapshot;
        $this->assertSame('Passage text', $withPassage['passage']['content']);
        // Lưu đường dẫn trong kho (không phải URL tuyệt đối chứa APP_URL); URL dựng lúc trả props.
        $this->assertSame('question-bank/p.png', $withPassage['passage']['image_path']);
        $this->assertArrayNotHasKey('image_url', $withPassage['passage']);
        $this->assertSame('Đọc đoạn văn', $withPassage['instruction']);
        $this->assertNotNull($items->firstWhere('type', 'Điền từ – đoạn văn')->question_id);

        $props = app(PracticeAttemptProps::class)->question($items->firstWhere('type', 'Điền từ – đoạn văn'));
        $this->assertSame(Storage::disk('public')->url('question-bank/p.png'), $props['passage']['image_url']);
        $this->assertNull($props['image_url']);
    }

    public function test_props_still_read_legacy_snapshots_with_image_url(): void
    {
        $item = new PracticeAttemptItem([
            'position' => 1, 'skill' => 'Đọc hiểu', 'type' => 'Điền từ – đoạn văn',
            'snapshot' => [
                'instruction' => null, 'stem' => 'S', 'options' => ['A' => 'a'], 'answer' => 'A', 'knowledge' => null,
                'image_url' => 'https://old.example/storage/q.png',
                'passage' => ['content' => 'P', 'image_url' => 'https://old.example/storage/p.png'],
            ],
        ]);

        $props = app(PracticeAttemptProps::class)->question($item);

        $this->assertSame('https://old.example/storage/q.png', $props['image_url']);
        $this->assertSame('https://old.example/storage/p.png', $props['passage']['image_url']);
    }

    public function test_start_again_resumes_in_progress_attempt(): void
    {
        $this->seedBank();
        $first = $this->service->start($this->student, $this->user, 'gk1');

        $this->travel(10)->minutes();
        $again = $this->service->start($this->student, $this->user, 'gk1');

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, PracticeAttempt::count());
    }

    public function test_start_after_deadline_finalizes_old_attempt_and_creates_new(): void
    {
        $this->seedBank();
        $first = $this->service->start($this->student, $this->user, 'gk1');

        $this->travel(46)->minutes();
        $second = $this->service->start($this->student, $this->user, 'gk1');

        $this->assertNotSame($first->id, $second->id);
        $first->refresh();
        $this->assertSame(PracticeAttempt::SUBMITTED, $first->status);
        $this->assertSame('timeout', $first->submit_reason);
        $this->assertTrue($first->auto_submitted);
        $this->assertSame(PracticeAttempt::IN_PROGRESS, $second->status);
    }

    public function test_start_avoids_questions_from_recent_attempts(): void
    {
        $this->seedBank(copies: 2);
        $first = $this->service->start($this->student, $this->user, 'gk1');
        $this->service->finalize($first);

        $second = $this->service->start($this->student, $this->user, 'gk1');

        $firstIds = $first->items()->pluck('question_id')->all();
        $secondIds = $second->items()->pluck('question_id')->all();
        $this->assertCount(40, $secondIds);
        $this->assertSame([], array_values(array_intersect($firstIds, $secondIds)));
    }

    public function test_start_errors(): void
    {
        $noDob = $this->makeStudent(null);
        $this->assertStringStartsWith('Chưa xác định khối', (string) $this->exceptionMessage(
            fn () => $this->service->start($noDob, $this->user, 'gk1')
        ));

        $this->assertSame('Chưa có đề cho khối 7 – Giữa kì 1.', $this->exceptionMessage(
            fn () => $this->service->start($this->student, $this->user, 'gk1')
        ));

        $grade5 = $this->makeStudent('2016-03-10');
        $this->seedBank(grade: 5);
        $this->assertSame('Chưa có đề cho khối 5 – Cuối kì 2.', $this->exceptionMessage(
            fn () => $this->service->start($grade5, $this->user, 'ck2')
        ));

        $this->assertSame('Kỳ luyện tập không hợp lệ.', $this->exceptionMessage(
            fn () => $this->service->start($this->student, $this->user, 'xx')
        ));

        $this->assertSame(0, PracticeAttempt::count());
    }

    public function test_save_answer_respects_grace_period(): void
    {
        $this->seedBank();
        $attempt = $this->service->start($this->student, $this->user, 'gk1');

        $this->travelTo($attempt->deadline_at->copy()->addSeconds(29));
        $this->service->saveAnswer($attempt, 1, 'C');
        $this->assertSame('C', $attempt->items()->where('position', 1)->value('chosen'));

        $this->travelTo($attempt->deadline_at->copy()->addSeconds(31));
        $this->assertNotNull($this->exceptionMessage(fn () => $this->service->saveAnswer($attempt, 2, 'A')));
        $this->assertNull($attempt->items()->where('position', 2)->value('chosen'));
    }

    public function test_save_answer_validation(): void
    {
        $this->seedBank();
        $attempt = $this->service->start($this->student, $this->user, 'gk1');

        $this->service->saveAnswer($attempt, 3, 'D');
        $this->service->saveAnswer($attempt, 3, null);
        $this->assertNull($attempt->items()->where('position', 3)->value('chosen'));

        $this->assertSame('Câu hỏi không tồn tại trong bài làm.', $this->exceptionMessage(
            fn () => $this->service->saveAnswer($attempt, 41, 'A')
        ));
        $this->assertNotNull($this->exceptionMessage(fn () => $this->service->saveAnswer($attempt, 1, 'E')));

        $this->service->finalize($attempt);
        $this->assertNotNull($this->exceptionMessage(fn () => $this->service->saveAnswer($attempt, 1, 'A')));
        $this->assertNull($attempt->items()->where('position', 1)->value('chosen'));
    }

    public function test_finalize_scores_and_is_idempotent(): void
    {
        $this->seedBank();
        $attempt = $this->service->start($this->student, $this->user, 'gk1');

        // 30 câu đúng (B), 5 câu sai, 5 câu bỏ trống
        foreach (range(1, 30) as $p) {
            $this->service->saveAnswer($attempt, $p, 'B');
        }
        foreach (range(31, 35) as $p) {
            $this->service->saveAnswer($attempt, $p, 'A');
        }

        $result = $this->service->finalize($attempt);

        $this->assertSame(PracticeAttempt::SUBMITTED, $result->status);
        $this->assertSame(40, $result->total);
        $this->assertSame(30, $result->correct);
        $this->assertSame('7.50', $result->score);
        $this->assertFalse($result->auto_submitted);
        $this->assertNull($result->submit_reason);
        $this->assertSame([
            'Ngữ âm' => ['correct' => 4, 'total' => 4],
            'Từ vựng & Ngữ pháp' => ['correct' => 14, 'total' => 14],
            'Giao tiếp & Biển báo' => ['correct' => 4, 'total' => 4],
            'Đọc hiểu' => ['correct' => 8, 'total' => 10],
            'Viết' => ['correct' => 0, 'total' => 8],
        ], $result->skill_scores);
        $this->assertSame(30, PracticeAttemptItem::where('attempt_id', $attempt->id)->where('is_correct', true)->count());
        $this->assertSame(10, PracticeAttemptItem::where('attempt_id', $attempt->id)->where('is_correct', false)->count());

        $submittedAt = $result->submitted_at;
        $this->travel(5)->minutes();
        $again = $this->service->finalize($attempt->fresh(), 'timeout');

        $this->assertSame('7.50', $again->score);
        $this->assertTrue($again->submitted_at->equalTo($submittedAt));
        $this->assertNull($again->submit_reason);
    }

    public function test_violations_finalize_at_max_and_are_ignored_after_submit(): void
    {
        $this->seedBank();
        $attempt = $this->service->start($this->student, $this->user, 'gk1');

        $attempt = $this->service->recordViolation($attempt, 'unknown');
        $this->assertSame(0, $attempt->violation_count);

        $attempt = $this->service->recordViolation($attempt, 'hidden');
        $attempt = $this->service->recordViolation($attempt, 'blur');
        $this->assertSame(PracticeAttempt::IN_PROGRESS, $attempt->status);
        $this->assertSame(2, $attempt->violation_count);

        $attempt = $this->service->recordViolation($attempt, 'fullscreen_exit');
        $this->assertSame(PracticeAttempt::SUBMITTED, $attempt->status);
        $this->assertSame('violations', $attempt->submit_reason);
        $this->assertTrue($attempt->auto_submitted);
        $this->assertSame(3, $attempt->violation_count);
        $this->assertSame(['hidden', 'blur', 'fullscreen_exit'], array_column($attempt->violation_log, 'type'));

        $after = $this->service->recordViolation($attempt, 'hidden');
        $this->assertSame(3, $after->violation_count);
        $this->assertSame(3, $attempt->fresh()->violation_count);
    }

    public function test_close_expired_only_finalizes_expired_attempts(): void
    {
        $this->seedBank(term: 'gk1');
        $this->seedBank(term: 'ck1');
        $expired = $this->service->start($this->student, $this->user, 'gk1');

        $this->travel(30)->minutes();
        $live = $this->service->start($this->student, $this->user, 'ck1');

        $this->travel(16)->minutes(); // gk1 quá hạn 1 phút, ck1 còn 29 phút
        $this->assertSame(1, $this->service->closeExpired());

        $this->assertSame(PracticeAttempt::SUBMITTED, $expired->fresh()->status);
        $this->assertSame('timeout', $expired->fresh()->submit_reason);
        $this->assertSame(PracticeAttempt::IN_PROGRESS, $live->fresh()->status);
        $this->assertSame(0, $this->service->closeExpired());
    }

    public function test_close_expired_command(): void
    {
        $this->seedBank();
        $attempt = $this->service->start($this->student, $this->user, 'gk1');
        $this->travel(50)->minutes();

        $this->artisan('practice:close-expired')->assertSuccessful();

        $this->assertSame(PracticeAttempt::SUBMITTED, $attempt->fresh()->status);
    }
}
