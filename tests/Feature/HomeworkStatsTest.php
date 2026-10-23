<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\Student;
use App\Models\SyllabusLesson;
use App\Services\Homework\HomeworkStats;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** GV giao bài theo syllabus, xem bài nộp; thống kê tỉ lệ nộp theo giỏ × học sinh và theo lớp (docs/kho-syllabus.md §10.2). */
class HomeworkStatsTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private Student $a;

    private Student $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
        $this->a = $this->makeStudent('An', 1);
        $this->b = $this->makeStudent('Bình', 2);
    }

    public function test_homework_form_prefilled_from_today_lesson(): void
    {
        $this->openStage($this->stage1, ['start_date' => today(), 'opened_at' => today(), 'confirmed_at' => now()]);
        $lesson = SyllabusLesson::where('curriculum_id', $this->curriculum->id)->where('session_no', 1)->firstOrFail();
        $lesson->update(['homework_guide' => 'Workbook trang 4', 'homework_quizizz' => 'Quizizz Vocab U1', 'homework_video' => 'Đọc đoạn 1',
            'quizizz_url' => 'https://quizizz.com/join?gc=1']);
        ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->toDateString(),
            'start_time' => '18:00', 'end_time' => '19:30', 'type' => 'regular', 'status' => 'scheduled', 'teacher_id' => $this->teacher->id]);

        $this->actingAs($this->teacher)->get(route('teacher.lesson-today', ['class' => $this->class->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('lesson.homework_url', route('teacher.homework', ['classId' => $this->class->id, 'lesson' => $lesson->id]))->etc());
        $this->actingAs($this->teacher)->get(route('teacher.homework', ['classId' => $this->class->id, 'lesson' => $lesson->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('prefill.title', 'Bài tập buổi 1: Bài 1')
                ->where('prefill.items', ['workbook' => 'Workbook trang 4', 'quiz' => 'Quizizz Vocab U1', 'video' => 'Đọc đoạn 1'])
                ->where('prefill.quizizz_url', 'https://quizizz.com/join?gc=1')
                ->etc());
    }

    public function test_teacher_reviews_submission(): void
    {
        $submission = $this->submit($this->homework(['workbook' => 'x']), $this->a, 'paper');

        $this->actingAs($this->teacher)->get(route('teacher.homework-submissions', $this->class->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('homeworks.0.rows.0.student', 'An')->where('homeworks.0.rows.0.baskets.paper.status', 'submitted')->etc());
        $this->actingAs($this->teacher)->post(route('teacher.homework-submissions.review', $submission->id))->assertSessionHasNoErrors();

        $this->assertSame(['reviewed', $this->teacher->id], [$submission->fresh()->status, $submission->fresh()->reviewed_by]);
        $outsider = $this->userWithRole(Roles::TEACHER_FULLTIME, 'GV lớp khác');
        $this->actingAs($outsider)->post(route('teacher.homework-submissions.review', $submission->id))->assertNotFound();
        $this->actingAs($outsider)->get(route('teacher.homework-submissions', $this->class->id))->assertNotFound();
    }

    public function test_stats_by_student_and_class_per_basket(): void
    {
        $full = $this->homework(['workbook' => 'Trang 4', 'quiz' => 'Vocab', 'video' => 'Đọc']);
        $paperOnly = $this->homework(['vocabulary' => 'Chép từ']);
        $this->homework([], ['class_note' => 'Ôn bài']);
        $this->submit($full, $this->a, 'paper');
        $this->submit($full, $this->a, 'quizizz');
        $this->submit($paperOnly, $this->a, 'paper');

        $stats = app(HomeworkStats::class)->forClass($this->class, today()->subDays(30), today());

        $this->assertSame(2, $stats['homeworks'], 'Bài tập chỉ có ghi chú không tính.');
        $rowA = collect($stats['students'])->firstWhere('id', $this->a->id);
        $this->assertSame(['submitted' => 2, 'assigned' => 2, 'rate' => 100.0], $rowA['baskets']['paper']);
        $this->assertSame(['submitted' => 0, 'assigned' => 1, 'rate' => 0.0], $rowA['baskets']['video']);
        $this->assertSame(['submitted' => 3, 'assigned' => 4, 'rate' => 75.0], $rowA['total']);
        $this->assertSame(['submitted' => 0, 'assigned' => 4, 'rate' => 0.0], collect($stats['students'])->firstWhere('id', $this->b->id)['total']);
        $this->assertSame(['submitted' => 2, 'assigned' => 4, 'rate' => 50.0], $stats['class']['paper']);
        $this->assertSame(['submitted' => 3, 'assigned' => 8, 'rate' => 37.5], $stats['class']['total']);
    }

    public function test_empty_range_has_no_rate(): void
    {
        $old = $this->homework(['workbook' => 'Trang 1']);
        $old->forceFill(['created_at' => today()->subDays(60)])->save();

        $stats = app(HomeworkStats::class)->forClass($this->class, today()->subDays(30), today());

        $this->assertSame(0, $stats['homeworks']);
        $this->assertSame(['submitted' => 0, 'assigned' => 0, 'rate' => null], $stats['class']['total']);
    }

    public function test_class_tab_shows_stats_for_staff(): void
    {
        $hw = $this->homework(['workbook' => 'Trang 4']);
        $this->submit($hw, $this->a, 'paper');

        $this->actingAs($this->staff)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'homework']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('homework.class.total.rate', 50)
                ->where('homework.students.0.name', 'An')
                ->etc());
    }

    private function homework(array $items, array $attributes = []): Homework
    {
        return Homework::create($attributes + ['class_id' => $this->class->id, 'user_id' => $this->teacher->id, 'title' => 'Bài tập', 'items' => $items]);
    }

    private function submit(Homework $homework, Student $student, string $basket): HomeworkSubmission
    {
        return HomeworkSubmission::create(['homework_id' => $homework->id, 'student_id' => $student->id, 'basket' => $basket, 'status' => 'submitted', 'submitted_at' => now()]);
    }

    private function makeStudent(string $name, int $n): Student
    {
        return Student::create(['name' => $name, 'code' => "HV-ST{$n}", 'phone' => '091000000'.$n, 'status' => 'studying',
            'current_class_id' => $this->class->id, 'branch_id' => $this->branch->id]);
    }
}
