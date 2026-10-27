<?php

namespace Tests\Feature;

use App\Jobs\ImportPracticeExams;
use App\Models\Branch;
use App\Models\PracticeAttempt;
use App\Models\PracticeExam;
use App\Models\PracticeExamImport;
use App\Models\QuestionBankQuestion;
use App\Models\Student;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\PracticeExamFixture;
use Tests\TestCase;

/** Màn Đề cố định của Học thuật (docs/luyen-tap-ngan-hang-cau-hoi.md §11.2). */
class PracticeExamControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(9, 0));
        $this->lead = $this->userWithRole(Roles::ACADEMIC_LEAD);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        PracticeExamFixture::cleanup();
        parent::tearDown();
    }

    private function userWithRole(string $role): User
    {
        $branch = Branch::query()->firstOrCreate(['code' => 'PE-CG'], ['name' => 'Cầu Giấy', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $user->assignRole($role);

        return $user;
    }

    private function importFixture(): PracticeExam
    {
        $this->actingAs($this->lead)
            ->post(route('question-bank.exams.import'), ['files' => [
                new UploadedFile(PracticeExamFixture::xlsx(), 'De_GK1_Anh6.xlsx', null, null, true),
                new UploadedFile(PracticeExamFixture::docx(), 'De_GK1_Anh6_Ma601.docx', null, null, true),
            ]])
            ->assertRedirect()
            ->assertSessionHas('status');

        return PracticeExam::query()->firstOrFail();
    }

    private function attemptFor(PracticeExam $exam): void
    {
        $student = Student::create([
            'name' => 'HV', 'code' => 'HV-PE', 'phone' => '0911000077', 'status' => 'active',
            'branch_id' => Branch::query()->value('id'), 'dob' => '2015-01-01',
        ]);
        PracticeAttempt::create([
            'student_id' => $student->id, 'exam_id' => $exam->id, 'grade' => $exam->grade, 'term' => $exam->term,
            'status' => PracticeAttempt::SUBMITTED, 'started_at' => now(), 'deadline_at' => now()->addHour(),
            'submitted_at' => now(), 'total' => 6, 'correct' => 3, 'score' => 5,
        ]);
    }

    public function test_only_question_bank_viewers_open_exam_pages(): void
    {
        $this->actingAs($this->userWithRole(Roles::ACADEMIC_STAFF))->get(route('question-bank.exams.index'))->assertForbidden();
        $this->actingAs($this->userWithRole(Roles::TEACHER_FULLTIME))->get(route('question-bank.exams.index'))->assertForbidden();

        $this->actingAs($this->lead)->get(route('question-bank.exams.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('QuestionBank/Exams')
                ->has('grades', 4)
                ->where('grades.0.grade', 6)
                ->where('canManage', true));
    }

    public function test_import_lists_exam_by_grade_and_show_has_questions_with_answers(): void
    {
        $exam = $this->importFixture();
        $this->assertSame(PracticeExam::DRAFT, $exam->status);

        $this->get(route('question-bank.exams.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('imports.0.status', PracticeExamImport::DONE)
                ->where('imports.0.pending', false)
                ->where('imports.0.files', ['De_GK1_Anh6.xlsx', 'De_GK1_Anh6_Ma601.docx'])
                ->has('imports.0.report.saved', 1)
                ->where('grades.0.exams.0.label', 'Đề 601')
                ->where('grades.0.exams.0.question_count', 6)
                ->where('grades.0.exams.0.status_label', 'Nháp')
                ->has('grades.1.exams', 0));

        $this->get(route('question-bank.exams.show', $exam->id))
            ->assertOk()
            ->assertSee('Câu 6')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('QuestionBank/ExamShow')
                ->has('questions', 6)
                ->where('questions.0.options.A', 'f[a]ther')
                ->where('questions.0.answer', 'B')
                ->where('questions.3.passage.id', fn ($id) => $id !== null));
    }

    public function test_publish_hide_and_draft_rules(): void
    {
        $exam = $this->importFixture();
        $status = fn (string $action) => $this->post(route('question-bank.exams.status', $exam->id), ['action' => $action]);

        $status('publish')->assertSessionHas('status');
        $this->assertSame(PracticeExam::PUBLISHED, $exam->fresh()->status);
        $this->assertNotNull($exam->fresh()->published_at);

        $status('hide')->assertSessionHas('status');
        $this->assertSame(PracticeExam::HIDDEN, $exam->fresh()->status);

        $this->attemptFor($exam);
        $status('draft')->assertSessionHas('error');
        $this->assertSame(PracticeExam::HIDDEN, $exam->fresh()->status);
        $this->delete(route('question-bank.exams.destroy', $exam->id))->assertSessionHas('error');
        $this->assertNotNull($exam->fresh());

        $this->actingAs($this->userWithRole(Roles::TEACHER_FULLTIME))
            ->post(route('question-bank.exams.status', $exam->id), ['action' => 'publish'])->assertForbidden();
    }

    public function test_update_and_destroy_exam_without_attempts(): void
    {
        $exam = $this->importFixture();

        $this->put(route('question-bank.exams.update', $exam->id), ['title' => 'Đề ôn GK1 – 601', 'duration_minutes' => 45])
            ->assertSessionHas('status');
        $this->assertSame(['Đề ôn GK1 – 601', 45], [$exam->fresh()->title, $exam->fresh()->duration_minutes]);

        $this->delete(route('question-bank.exams.destroy', $exam->id))->assertRedirect(route('question-bank.exams.index'));
        $this->assertNull($exam->fresh());
        $this->assertSame(0, QuestionBankQuestion::query()->count());
    }

    public function test_exam_question_edits_without_stem_or_status_and_returns_to_exam(): void
    {
        $exam = $this->importFixture();
        $question = $exam->questions()->where('position', 1)->firstOrFail();
        $back = "/question-bank/exams/{$exam->id}";

        $this->put(route('question-bank.questions.update', $question->id), [
            'instruction' => 'Mark the letter',
            'stem' => '',
            'options' => ['A' => 'f[a]ther', 'B' => 'br[o]ther', 'C' => 'c[a]rd', 'D' => 'cl[a]ss'],
            'answer' => 'C',
            'return' => $back,
        ])->assertRedirect($back);

        $question->refresh();
        $this->assertSame(['C', '', 'approved'], [$question->answer, $question->stem, $question->status]);
        $this->assertNotNull($question->edited_at);
        // không lẫn vào danh sách Ngân hàng câu hỏi
        $this->get(route('question-bank.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('questions.total', 0));
    }

    public function test_practice_results_filter_by_exam_or_mixed(): void
    {
        $exam = $this->importFixture();
        $exam->update(['status' => PracticeExam::PUBLISHED]);
        $this->attemptFor($exam);

        $this->get(route('practice-results.index', ['exam' => $exam->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.exam', $exam->id)
                ->where('summary.attempts', 1)
                ->where('filterOptions.exams.1.label', 'Khối 6 · Giữa kì 1 · Đề 601'));
        $this->get(route('practice-results.index', ['exam' => 'mixed']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.attempts', 0));

        $studentId = PracticeAttempt::query()->value('student_id');
        $this->get(route('practice-results.student', ['student' => $studentId, 'exam' => $exam->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('attempts.data.0.exam_label', 'Đề 601'));
    }

    public function test_import_rejects_other_file_types(): void
    {
        $this->actingAs($this->lead)
            ->post(route('question-bank.exams.import'), ['files' => [UploadedFile::fake()->create('de.pdf', 10, 'application/pdf')]])
            ->assertSessionHasErrors('files.0');
        $this->assertSame(0, PracticeExam::query()->count());
    }

    public function test_import_is_queued_with_stored_files_and_page_shows_pending(): void
    {
        config(['queue.default' => 'database']); // có worker thật: job vào hàng đợi
        Queue::fake();

        $this->actingAs($this->lead)
            ->post(route('question-bank.exams.import'), ['files' => [
                new UploadedFile(PracticeExamFixture::xlsx(), 'De_GK1_Anh6.xlsx', null, null, true),
            ]])
            ->assertSessionHas('status');

        $import = PracticeExamImport::query()->firstOrFail();
        $this->assertSame([PracticeExamImport::QUEUED, $this->lead->id], [$import->status, $import->user_id]);
        Storage::disk('local')->assertExists("exam-imports/{$import->id}/0");
        Queue::assertPushed(ImportPracticeExams::class, fn (ImportPracticeExams $job) => $job->import->is($import));
        $this->assertSame(0, PracticeExam::query()->count());

        $this->get(route('question-bank.exams.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('imports.0.pending', true)
                ->where('imports.0.stalled', false)
                ->where('imports.0.status_label', 'Chờ xử lý'));
        $this->travel(3)->minutes();
        $this->get(route('question-bank.exams.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('imports.0.stalled', true));

        // worker chạy job: ghi đề, lưu kết quả, xoá file đã tải lên
        app()->call([new ImportPracticeExams($import), 'handle']);
        $import->refresh();
        $this->assertSame(PracticeExamImport::DONE, $import->status);
        $this->assertNotNull($import->finished_at);
        $this->assertCount(1, $import->report['saved']);
        $this->assertSame(1, PracticeExam::query()->count());
        Storage::disk('local')->assertMissing("exam-imports/{$import->id}");
    }

    public function test_import_without_excel_fails_with_message(): void
    {
        $this->actingAs($this->lead)
            ->post(route('question-bank.exams.import'), ['files' => [
                new UploadedFile(PracticeExamFixture::docx(), 'De_GK1_Anh6_Ma601.docx', null, null, true),
            ]])
            ->assertSessionHas('status');

        $import = PracticeExamImport::query()->firstOrFail();
        $this->assertSame(PracticeExamImport::FAILED, $import->status);
        $this->assertStringContainsString('Không tìm thấy file Excel', $import->message);
        Storage::disk('local')->assertMissing("exam-imports/{$import->id}");

        $this->get(route('question-bank.exams.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('imports.0.status_label', 'Thất bại')
                ->where('imports.0.report', null));
    }

    public function test_bulk_publish_and_hide_selected_exams(): void
    {
        $exam = $this->importFixture();
        $empty = PracticeExam::query()->create(['grade' => 7, 'term' => 'gk1', 'code' => '701', 'title' => 'Đề 701', 'duration_minutes' => 45, 'status' => PracticeExam::DRAFT]);
        $bulk = fn (string $action) => $this->post(route('question-bank.exams.bulk'), ['action' => $action, 'ids' => [$exam->id, $empty->id]]);

        $bulk('publish')->assertSessionHas('status', 'Đã mở 1 đề. Bỏ qua 1 đề chưa có câu hỏi.');
        $this->assertSame([PracticeExam::PUBLISHED, PracticeExam::DRAFT], [$exam->fresh()->status, $empty->fresh()->status]);
        $this->assertNotNull($exam->fresh()->published_at);

        $bulk('hide')->assertSessionHas('status', 'Đã ẩn 2 đề.');
        $this->assertSame([PracticeExam::HIDDEN, PracticeExam::HIDDEN], [$exam->fresh()->status, $empty->fresh()->status]);

        $this->post(route('question-bank.exams.bulk'), ['action' => 'draft', 'ids' => [$exam->id]])->assertSessionHasErrors('action');
        $this->actingAs($this->userWithRole(Roles::TEACHER_FULLTIME))
            ->post(route('question-bank.exams.bulk'), ['action' => 'publish', 'ids' => [$exam->id]])->assertForbidden();
    }
}
