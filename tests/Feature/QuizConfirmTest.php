<?php

namespace Tests\Feature;

use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\Student;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** TK-2026-0038: HS "Nộp bài" Quiz (ảnh tùy chọn) + GV xác nhận / hủy xác nhận tùy chọn trên cùng bài nộp. */
class QuizConfirmTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private User $parent;

    private Student $student;

    private Homework $homework;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildSyllabusWorkflow();
        $this->parent = $this->userWithRole(Roles::STUDENT, 'Phụ huynh A');
        $this->student = Student::create(['name' => 'HS A', 'code' => 'HV-QZ1', 'phone' => '0900000001', 'status' => 'studying',
            'current_class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'user_id' => $this->parent->id]);
        $this->homework = Homework::create(['class_id' => $this->class->id, 'user_id' => $this->teacher->id, 'title' => 'Quiz U1',
            'items' => ['quiz' => 'Quizizz Vocab U1'], 'quizizz_url' => 'https://quizizz.com/join?gc=1', 'due_date' => today()->addDay()]);
    }

    public function test_student_submits_quiz_without_image_and_with_optional_image(): void
    {
        $this->actingAs($this->parent)->post($this->submitUrl(), ['student_id' => $this->student->id])->assertSessionHasNoErrors();
        $row = HomeworkSubmission::sole();
        $this->assertSame(['submitted', null, null], [$row->status, $row->file_path, $row->confirmed_at]);

        $this->actingAs($this->parent)->post($this->submitUrl(), ['student_id' => $this->student->id, 'file' => UploadedFile::fake()->image('kq.jpg')])->assertSessionHasNoErrors();
        $row = HomeworkSubmission::sole();
        $this->assertSame('kq.jpg', $row->original_name);
        Storage::disk('local')->assertExists($row->file_path);

        $this->actingAs($this->parent)->post($this->submitUrl(), ['student_id' => $this->student->id, 'file' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')])
            ->assertSessionHasErrors('file');
    }

    public function test_student_page_exposes_submission_and_can_attach(): void
    {
        $this->actingAs($this->parent)->post($this->submitUrl(), ['student_id' => $this->student->id]);

        $this->actingAs($this->parent)->get(route('portal.student.homework', $this->student->id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('assignments.0.baskets.0.key', 'quizizz')
                ->where('assignments.0.baskets.0.can_attach', true)
                ->where('assignments.0.baskets.0.submission.status', 'submitted')
                ->where('assignments.0.baskets.0.submission.confirmed_at', null)
                ->etc());
    }

    public function test_teacher_confirms_same_submission_keeping_student_submit_time(): void
    {
        $this->actingAs($this->parent)->post($this->submitUrl(), ['student_id' => $this->student->id]);
        $submitted = HomeworkSubmission::sole()->submitted_at;
        $this->travel(1)->hours();

        $this->actingAs($this->teacher)->post($this->confirmUrl(), $this->payload())->assertSessionHasNoErrors();

        $row = HomeworkSubmission::sole();
        $this->assertSame($this->teacher->id, $row->confirmed_by);
        $this->assertNotNull($row->confirmed_at);
        $this->assertTrue($row->submitted_at->equalTo($submitted), 'Giờ nộp của HS không đổi.');
        $this->assertNull($row->created_by);
    }

    public function test_student_resubmit_clears_confirmation(): void
    {
        $this->actingAs($this->parent)->post($this->submitUrl(), ['student_id' => $this->student->id]);
        $this->actingAs($this->teacher)->post($this->confirmUrl(), $this->payload());

        $this->actingAs($this->parent)->post($this->submitUrl(), ['student_id' => $this->student->id])->assertSessionHasNoErrors();

        $row = HomeworkSubmission::sole();
        $this->assertSame([null, null], [$row->confirmed_by, $row->confirmed_at]);
    }

    public function test_cancel_case_a_teacher_created_submission_is_deleted(): void
    {
        $this->actingAs($this->teacher)->post($this->confirmUrl(), $this->payload())->assertSessionHasNoErrors();
        $row = HomeworkSubmission::sole();
        $this->assertSame($this->teacher->id, $row->created_by);

        $this->actingAs($this->teacher)->post($this->cancelUrl(), $this->payload())->assertSessionHasNoErrors();

        $this->assertSame(0, HomeworkSubmission::count(), 'HS về Chưa nộp.');
    }

    public function test_cancel_case_b_student_submission_keeps_submitted_state(): void
    {
        $this->actingAs($this->parent)->post($this->submitUrl(), ['student_id' => $this->student->id]);
        $this->actingAs($this->teacher)->post($this->confirmUrl(), $this->payload());

        $this->actingAs($this->teacher)->post($this->cancelUrl(), $this->payload())->assertSessionHasNoErrors();

        $row = HomeworkSubmission::sole();
        $this->assertSame(['submitted', null, null], [$row->status, $row->confirmed_by, $row->confirmed_at]);
    }

    public function test_cancel_case_c_nothing_to_cancel(): void
    {
        $this->actingAs($this->teacher)->post($this->cancelUrl(), $this->payload())->assertSessionHas('error');
        $this->actingAs($this->parent)->post($this->submitUrl(), ['student_id' => $this->student->id]);
        $this->actingAs($this->teacher)->post($this->cancelUrl(), $this->payload())->assertSessionHas('error');

        $this->assertSame(1, HomeworkSubmission::count(), 'Không xóa bài HS tự nộp khi chưa có xác nhận.');
    }

    public function test_only_own_class_teacher_can_confirm_or_cancel(): void
    {
        $other = $this->userWithRole(Roles::TEACHER_FULLTIME, 'GV lớp khác');

        $this->actingAs($other)->post($this->confirmUrl(), $this->payload())->assertNotFound();
        $this->actingAs($other)->post($this->cancelUrl(), $this->payload())->assertNotFound();
        $this->actingAs($this->parent)->post($this->confirmUrl(), $this->payload())->assertForbidden();
        $this->assertSame(0, HomeworkSubmission::count());
    }

    public function test_cannot_confirm_student_outside_class_or_non_quiz_homework(): void
    {
        $outsider = Student::create(['name' => 'HS ngoài lớp', 'code' => 'HV-QZ2', 'phone' => '0900000002', 'status' => 'active', 'branch_id' => $this->branch->id]);
        $this->actingAs($this->teacher)->post($this->confirmUrl(), ['homework_id' => $this->homework->id, 'student_id' => $outsider->id])->assertNotFound();

        $paperOnly = Homework::create(['class_id' => $this->class->id, 'user_id' => $this->teacher->id, 'title' => 'Chỉ giấy', 'items' => ['workbook' => 'Trang 1']]);
        $this->actingAs($this->teacher)->post($this->confirmUrl(), ['homework_id' => $paperOnly->id, 'student_id' => $this->student->id])->assertNotFound();
        $this->assertSame(0, HomeworkSubmission::count());
    }

    public function test_quiz_tab_and_session_table_use_same_data_and_no_score_for_quiz(): void
    {
        $this->actingAs($this->parent)->post($this->submitUrl(), ['student_id' => $this->student->id, 'file' => UploadedFile::fake()->image('kq.jpg')]);
        $this->actingAs($this->teacher)->post($this->confirmUrl(), $this->payload());

        $this->actingAs($this->teacher)->get(route('portal.teacher.submissions', ['classId' => $this->class->id, 'type' => 'quiz']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('quiz.submitted', 1)
                ->where('quiz.homeworks.0.rows.0.submission.self_submitted', true)
                ->where('quiz.homeworks.0.rows.0.submission.confirmed_by', $this->teacher->name)
                ->has('quiz.homeworks.0.rows.0.submission.file_url')
                ->etc());

        $this->actingAs($this->teacher)->get(route('portal.teacher.submissions', ['classId' => $this->class->id, 'type' => 'basket']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('basket.homeworks.0.rows.0.baskets.quizizz.id', HomeworkSubmission::sole()->id)
                ->where('basket.homeworks.0.rows.0.baskets.quizizz.confirmed_by', $this->teacher->name)
                ->etc());

        // F-27: Quiz không chấm điểm.
        $this->actingAs($this->teacher)->post(route('teacher.homework-submissions.review', HomeworkSubmission::sole()->id), ['score' => '9'])->assertStatus(422);
        $this->assertNull(HomeworkSubmission::sole()->score);
    }

    public function test_migration_is_reversible(): void
    {
        $migration = require base_path('database/migrations/2026_12_01_090000_add_quiz_confirmation_to_homework_submissions.php');
        $migration->down();
        $this->assertFalse(\Schema::hasColumn('homework_submissions', 'confirmed_at'));
        $migration->up();
        $this->assertTrue(\Schema::hasColumns('homework_submissions', ['confirmed_by', 'confirmed_at', 'created_by']));
    }

    private function payload(): array
    {
        return ['homework_id' => $this->homework->id, 'student_id' => $this->student->id];
    }

    private function submitUrl(): string
    {
        return route('portal.student.homework.basket', [$this->homework->id, 'quizizz']);
    }

    private function confirmUrl(): string
    {
        return route('teacher.homework-submissions.quiz-confirm');
    }

    private function cancelUrl(): string
    {
        return route('teacher.homework-submissions.quiz-cancel');
    }
}
