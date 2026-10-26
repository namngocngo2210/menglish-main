<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PracticeAttempt;
use App\Models\PracticeExam;
use App\Models\QuestionBankPassage;
use App\Models\QuestionBankQuestion;
use App\Models\Student;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Học viên chọn đề cố định theo khối ở cổng học viên (docs/luyen-tap-ngan-hang-cau-hoi.md §11.3). */
class PracticeExamPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        // năm học 2026–2027: sinh 2014 → khối 7
        $this->travelTo(now()->setDate(2026, 10, 23)->setTime(9, 0));

        $branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-DE', 'is_active' => true]);
        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole(Roles::STUDENT);
        $this->student = Student::create([
            'user_id' => $this->user->id, 'name' => 'Học viên đề cố định', 'code' => 'HV-DE-1', 'phone' => '0911000099',
            'status' => 'active', 'branch_id' => $branch->id, 'dob' => '2014-03-10',
        ]);
    }

    /** Đề 3 câu: câu 1 lẻ, câu 2–3 chung ngữ liệu; đáp án A, B, C. */
    private function exam(string $code, int $grade = 7, string $status = PracticeExam::PUBLISHED, string $term = 'gk1'): PracticeExam
    {
        $exam = PracticeExam::create([
            'grade' => $grade, 'term' => $term, 'code' => $code, 'title' => "Đề kiểm tra {$code}",
            'duration_minutes' => 60, 'status' => $status,
        ]);
        $common = ['exam_id' => $exam->id, 'grade' => $grade, 'term' => $term];
        $passage = QuestionBankPassage::create($common + [
            'skill' => 'Đọc hiểu', 'type' => 'Đọc – Điền từ', 'instruction' => 'Read the passage', 'content' => 'I (2) ___ (3) ___.',
            'source_key' => "EX{$exam->id}-P2",
        ]);
        foreach ([1 => 'A', 2 => 'B', 3 => 'C'] as $no => $answer) {
            QuestionBankQuestion::create($common + [
                'passage_id' => $no > 1 ? $passage->id : null, 'position' => $no,
                'skill' => $no > 1 ? 'Đọc hiểu' : 'Ngữ âm', 'type' => $no > 1 ? 'Đọc – Điền từ' : 'Phát âm',
                'instruction' => $no > 1 ? null : 'Mark the letter', 'stem' => $no > 1 ? "Chỗ trống ({$no})" : '',
                'options' => ['A' => 'f[a]ther', 'B' => 'b', 'C' => 'c', 'D' => 'd'], 'answer' => $answer,
                'status' => 'approved', 'source_key' => "EX{$exam->id}-Q{$no}",
            ]);
        }

        return $exam;
    }

    private function start(PracticeExam $exam)
    {
        return $this->actingAs($this->user)
            ->post(route('portal.student.practice.start'), ['student_id' => $this->student->id, 'exam_id' => $exam->id]);
    }

    public function test_index_lists_only_published_exams_of_the_students_grade(): void
    {
        $this->exam('702');
        $this->exam('701');
        $this->exam('703', status: PracticeExam::DRAFT);
        $this->exam('601', grade: 6);
        $this->exam('711', term: 'ck1');

        $this->actingAs($this->user)
            ->get(route('portal.student.practice', ['studentId' => $this->student->id]))
            ->assertOk()
            ->assertSee('Đề cố định khối 7')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Practice')
                ->has('exams', 3)
                ->where('exams.0.label', 'Đề 701')
                ->where('exams.1.label', 'Đề 702')
                ->where('exams.2.label', 'Đề 711')
                ->where('exams.2.term_label', 'Cuối kì 1')
                ->where('exams.0.question_count', 3)
                ->where('exams.0.duration', 60)
                ->where('exams.0.attempts', 0)
                ->where('exams.0.inProgressAttemptId', null));
    }

    public function test_start_creates_attempt_in_exam_order_without_answers_and_resumes(): void
    {
        $exam = $this->exam('701');

        $response = $this->start($exam);
        $attempt = PracticeAttempt::query()->latest('id')->firstOrFail();
        $response->assertRedirect(route('portal.student.practice.attempt', $attempt->id));
        $this->assertSame([$exam->id, 7, 'gk1'], [$attempt->exam_id, $attempt->grade, $attempt->term]);
        $this->assertTrue($attempt->deadline_at->equalTo(now()->addMinutes(60)));
        $this->assertSame([1, 2, 3], $attempt->items()->pluck('position')->all());
        $this->assertSame('f[a]ther', $attempt->items()->first()->snapshot['options']['A']);

        $this->actingAs($this->user)->get(route('portal.student.practice.attempt', $attempt->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/PracticeTake')
                ->where('attempt.exam_label', 'Đề 701')
                ->has('items', 3)
                ->missing('items.0.answer')
                ->where('items.1.passage.content', 'I (2) ___ (3) ___.'));

        // bấm lại → làm tiếp lượt cũ; thẻ đề hiện "Làm tiếp"
        $this->start($exam)->assertRedirect(route('portal.student.practice.attempt', $attempt->id));
        $this->assertSame(1, PracticeAttempt::query()->count());
        $this->actingAs($this->user)->get(route('portal.student.practice', ['studentId' => $this->student->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('exams.0.inProgressAttemptId', $attempt->id));
    }

    public function test_submit_scores_and_history_shows_exam_label(): void
    {
        $exam = $this->exam('701');
        $this->start($exam);
        $attempt = PracticeAttempt::query()->latest('id')->firstOrFail();

        $this->postJson(route('portal.student.practice.answer', $attempt->id), ['position' => 1, 'choice' => 'A'])->assertOk();
        $this->postJson(route('portal.student.practice.answer', $attempt->id), ['position' => 2, 'choice' => 'B'])->assertOk();
        $this->post(route('portal.student.practice.submit', $attempt->id))->assertRedirect();

        $attempt->refresh();
        $this->assertSame(PracticeAttempt::SUBMITTED, $attempt->status);
        $this->assertSame(2, $attempt->correct);
        $this->assertSame('6.67', $attempt->score);

        $this->get(route('portal.student.practice.attempt', $attempt->id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/PracticeResult')
                ->where('attempt.exam_label', 'Đề 701'));
        $this->get(route('portal.student.practice', ['studentId' => $this->student->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('history.data.0.exam_label', 'Đề 701')
                ->where('exams.0.attempts', 1)
                ->where('exams.0.best_score', 6.67));
    }

    public function test_exam_and_mixed_attempts_of_the_same_term_are_independent(): void
    {
        $exam = $this->exam('701');
        $this->start($exam);
        $examAttempt = PracticeAttempt::query()->latest('id')->firstOrFail();

        // đề trộn gk1 không có câu trong kho → báo chưa có đề, không lấy nhầm lượt đề cố định
        $this->actingAs($this->user)
            ->post(route('portal.student.practice.start'), ['student_id' => $this->student->id, 'term' => 'gk1'])
            ->assertSessionHas('error');
        $this->assertSame(1, PracticeAttempt::query()->count());

        $this->get(route('portal.student.practice', ['studentId' => $this->student->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('terms.0.inProgressAttemptId', null)
                ->where('exams.0.inProgressAttemptId', $examAttempt->id));
    }

    public function test_cannot_start_draft_hidden_or_other_grade_exam(): void
    {
        foreach ([$this->exam('703', status: PracticeExam::DRAFT), $this->exam('704', status: PracticeExam::HIDDEN), $this->exam('601', grade: 6)] as $exam) {
            $this->start($exam)->assertSessionHas('error');
        }
        $this->assertSame(0, PracticeAttempt::query()->count());

        $this->actingAs($this->user)
            ->post(route('portal.student.practice.start'), ['student_id' => $this->student->id, 'exam_id' => 99999])
            ->assertNotFound();
    }
}
