<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\Student;
use App\Models\SyllabusLesson;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** Lỗi nhỏ để sau của giai đoạn 5 (docs/kho-syllabus.md §10): mỗi test tái hiện một lỗi. */
class HomeworkDeferredMinorsTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildSyllabusWorkflow();
        $this->student = Student::create(['name' => 'An', 'code' => 'HV-DM1', 'phone' => '0930000001', 'status' => 'studying',
            'current_class_id' => $this->class->id, 'branch_id' => $this->branch->id]);
    }

    public function test_submissions_page_loads_homeworks_and_submissions_once(): void
    {
        foreach (range(1, 3) as $i) {
            $this->submit($this->homework(['workbook' => "Trang {$i}", 'video' => 'Đọc']), 'paper');
        }

        DB::enableQueryLog();
        $this->actingAs($this->teacher)->get(route('teacher.homework-submissions', $this->class->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('homeworks.0.rows.0.baskets.paper.status', 'submitted')->where('stats.class.paper.rate', 100)->etc());
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertSame(1, $queries->filter(fn ($q) => preg_match('/from "homeworks"/', $q))->count(), 'Bài tập chỉ truy vấn 1 lần.');
        $this->assertSame(1, $queries->filter(fn ($q) => preg_match('/from "homework_submissions"/', $q))->count(), 'Bài nộp chỉ truy vấn 1 lần.');
    }

    public function test_class_wide_grader_without_student_view_opens_file(): void
    {
        $grader = $this->userWithRole(Roles::TEACHER_FULLTIME, 'GV phạm vi toàn hệ thống');
        $grader->givePermissionTo('class.scope_all');
        $this->assertFalse($grader->can('student.view'));
        Storage::disk('local')->put('homework_submissions/bai.jpg', 'x');
        $row = $this->submit($this->homework(['workbook' => 'Trang 4']), 'paper', ['file_path' => 'homework_submissions/bai.jpg', 'original_name' => 'bai.jpg']);

        $this->actingAs($grader)->get(route('teacher.homework-submissions', $this->class->id))->assertOk();
        $this->actingAs($grader)->get(route('portal.student.homework-file', $row->id))->assertOk();
    }

    public function test_prefill_carries_the_session_of_the_lesson(): void
    {
        $this->openStage($this->stage1, ['start_date' => today(), 'opened_at' => today(), 'confirmed_at' => now()]);
        $lesson = SyllabusLesson::where('curriculum_id', $this->curriculum->id)->where('session_no', 1)->firstOrFail();
        $session = ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->toDateString(),
            'start_time' => '18:00', 'end_time' => '19:30', 'type' => 'regular', 'status' => 'scheduled', 'teacher_id' => $this->teacher->id]);

        $this->actingAs($this->teacher)->get(route('teacher.homework', ['classId' => $this->class->id, 'lesson' => $lesson->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('prefill.title', 'Bài tập buổi 1: Bài 1')->where('prefill.session_id', (string) $session->id)->etc());
    }

    public function test_quizizz_link_cannot_be_removed_after_submissions(): void
    {
        $session = ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->toDateString(),
            'start_time' => '18:00', 'end_time' => '19:30', 'type' => 'regular', 'status' => 'scheduled', 'teacher_id' => $this->teacher->id]);
        $hw = $this->homework(['workbook' => 'Trang 4'], ['class_session_id' => $session->id, 'quizizz_url' => 'https://quizizz.com/join?gc=1']);
        $this->submit($hw, 'quizizz');

        $this->actingAs($this->teacher)->put(route('teacher.homework.update', [$this->class->id, $hw->id]), [
            'class_session_id' => $session->id, 'categories' => ['workbook'], 'items' => ['workbook' => 'Trang 5'], 'quizizz_url' => '',
            'due_at' => today()->addDays(2)->setTime(20, 0)->toDateTimeString(),
        ])->assertSessionHasErrors('quizizz_url');

        $this->assertSame('https://quizizz.com/join?gc=1', $hw->fresh()->quizizz_url);
    }

    private function homework(array $items, array $attributes = []): Homework
    {
        return Homework::create($attributes + ['class_id' => $this->class->id, 'user_id' => $this->teacher->id, 'title' => 'Bài tập', 'items' => $items]);
    }

    private function submit(Homework $homework, string $basket, array $attributes = []): HomeworkSubmission
    {
        return HomeworkSubmission::create($attributes + ['homework_id' => $homework->id, 'student_id' => $this->student->id, 'basket' => $basket,
            'status' => 'submitted', 'submitted_at' => now()]);
    }
}
