<?php

namespace Tests\Feature;

use App\Models\ClassLessonProgress;
use App\Models\ClassSession;
use App\Models\MiniTestScore;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusLesson;
use App\Models\User;
use App\Services\Syllabus\ClassSyllabus;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** Màn "Bài giảng hôm nay" + nút Đã dạy (docs/kho-syllabus.md §8.3; quyết định 4, 7; Sheet 4 câu 11, 12). */
class TeacherLessonTodayTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private SyllabusAssignment $assignment;

    private ClassSession $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
        $this->class->update(['assistant_id' => ($this->assistant = $this->userWithRole(Roles::ASSISTANT, 'Trợ giảng'))->id]);
        $this->assignment = $this->openStage($this->stage1, ['start_date' => today()->subDays(7), 'opened_at' => today()->subDays(7), 'confirmed_at' => now()]);
        $this->lesson(1)->update(['content' => "Nội dung buổi 1\nchi tiết", 'ppt_url' => 'https://docs.google.com/presentation/d/PPT1/edit',
            'ppt_foreign_url' => 'https://docs.google.com/presentation/d/GVNN1/edit', 'homework_quizizz' => 'Quiz U1', 'procedure' => 'Warm up']);
        $this->lesson(2)->update(['content' => 'Nội dung buổi 2']);
        $this->today = $this->addSession(0);
    }

    private User $assistant;

    public function test_teacher_sees_only_today_lesson_with_effective_content_and_links(): void
    {
        $this->actingAs($this->teacher)->get(route('teacher.lesson-today', ['class' => $this->class->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Teacher/LessonToday')
                ->where('session.id', $this->today->id)
                ->where('lesson.session_no', 1)
                ->where('lesson.data.content', "Nội dung buổi 1\nchi tiết")
                ->where('lesson.data.ppt_foreign_url', 'https://docs.google.com/presentation/d/GVNN1/edit')
                ->where('can_mark', true)
                ->etc())
            ->assertDontSee('Nội dung buổi 2');
    }

    public function test_no_session_today_shows_notice_and_cannot_mark(): void
    {
        $this->today->update(['status' => 'cancelled']);

        $this->actingAs($this->teacher)->get(route('teacher.lesson-today', ['class' => $this->class->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('session', null)->where('lesson', null)->etc())
            ->assertSee('Hôm nay lớp không có buổi học');
        $this->actingAs($this->teacher)->post(route('teacher.lesson-today.taught', $this->class->id), ['class_session_id' => $this->today->id])
            ->assertSessionHasErrors('class_session_id');
    }

    public function test_mark_taught_advances_and_records_pace_note(): void
    {
        $this->actingAs($this->teacher)->post(route('teacher.lesson-today.taught', $this->class->id), [
            'class_session_id' => $this->today->id, 'pace_note' => 'Lớp chậm, chưa xong phần nói',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $mark = ClassLessonProgress::sole();
        $this->assertSame([$this->lesson(1)->id, $this->today->id, 'lesson', 'Lớp chậm, chưa xong phần nói', $this->teacher->id],
            [$mark->syllabus_lesson_id, $mark->class_session_id, $mark->kind, $mark->pace_note, $mark->taught_by]);
        $this->actingAs($this->teacher)->get(route('teacher.lesson-today', ['class' => $this->class->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('lesson.session_no', 1)->where('taught_today', true)->where('can_mark', false)->etc());
    }

    public function test_double_mark_is_rejected(): void
    {
        $url = route('teacher.lesson-today.taught', $this->class->id);
        $this->actingAs($this->teacher)->post($url, ['class_session_id' => $this->today->id])->assertSessionHasNoErrors();
        $this->actingAs($this->foreignTeacher)->post($url, ['class_session_id' => $this->today->id])->assertSessionHasErrors('class_session_id');

        $this->assertSame(1, ClassLessonProgress::count());
    }

    public function test_use_extra_requires_approved_extra_sessions(): void
    {
        $url = route('teacher.lesson-today.taught', $this->class->id);
        $this->actingAs($this->teacher)->post($url, ['class_session_id' => $this->today->id, 'use_extra' => '1'])->assertSessionHasErrors('use_extra');

        $this->assignment->update(['extra_sessions' => 1]);
        $this->actingAs($this->teacher)->post($url, ['class_session_id' => $this->today->id, 'use_extra' => '1'])->assertSessionHasNoErrors();

        $mark = ClassLessonProgress::sole();
        $this->assertSame(['extra', null], [$mark->kind, $mark->syllabus_lesson_id]);
        $this->assertSame(1, app(ClassSyllabus::class)->timeline($this->assignment)['current']['lesson']->session_no);
    }

    public function test_unmarked_past_session_can_be_marked(): void
    {
        $past = $this->addSession(-2);

        $this->actingAs($this->teacher)->get(route('teacher.lesson-today', ['class' => $this->class->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('unmarked.0.id', $past->id)->etc())
            ->assertSee('chưa ghi nhận Đã dạy');
        $this->actingAs($this->teacher)->post(route('teacher.lesson-today.taught', $this->class->id), ['class_session_id' => $past->id])->assertSessionHasNoErrors();

        $this->assertSame($past->id, ClassLessonProgress::sole()->class_session_id);
        $this->actingAs($this->teacher)->post(route('teacher.lesson-today.taught', $this->class->id), ['class_session_id' => $this->addSession(3)->id])
            ->assertSessionHasErrors('class_session_id');
    }

    public function test_submitting_session_report_records_taught_in_session_order(): void
    {
        $student = Student::create(['code' => 'HV-LT1', 'name' => 'Học viên LT', 'phone' => '0900000111', 'branch_id' => $this->branch->id,
            'status' => 'studying', 'current_class_id' => $this->class->id]);
        $past = $this->addSession(-2);
        foreach ([$past, $this->today] as $session) {
            StudentAttendance::create(['class_id' => $this->class->id, 'class_session_id' => $session->id, 'student_id' => $student->id,
                'user_id' => $this->teacher->id, 'session_date' => $session->date->toDateString(), 'status' => 'present']);
        }
        $report = fn (ClassSession $s) => route('classes.session-report.store', ['id' => $this->class->id, 'session' => $s->id]);

        // Bài giảng hôm nay không còn nút "Đã dạy": dẫn tới báo cáo buổi.
        $this->actingAs($this->teacher)->get(route('teacher.lesson-today', ['class' => $this->class->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('report_url', route('classes.session-report', ['id' => $this->class->id, 'session' => $this->today->id]))
                ->where('unmarked.0.report_url', route('classes.session-report', ['id' => $this->class->id, 'session' => $past->id]))->etc());

        // Buổi trước chưa có báo cáo: báo cáo hôm nay vẫn lưu, chưa ghi nhận Đã dạy.
        $this->actingAs($this->teacher)->post($report($this->today), ['content' => 'Unit 1'])->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Chưa ghi nhận Đã dạy'));
        $this->assertSame(0, ClassLessonProgress::count());

        // Nộp báo cáo buổi trước rồi sửa lại báo cáo hôm nay → ghi nhận theo thứ tự buổi.
        $this->actingAs($this->teacher)->post($report($past), ['content' => 'Unit 1'])->assertSessionHasNoErrors();
        $this->actingAs($this->teacher)->post($report($this->today), ['content' => 'Unit 2'])->assertSessionHasNoErrors();
        $this->assertSame([[$past->id, 1], [$this->today->id, 2]], ClassLessonProgress::orderBy('id')->get()
            ->map(fn ($m) => [(int) $m->class_session_id, $m->lesson?->session_no])->all());

        // Nộp lại không ghi nhận lần nữa.
        $this->actingAs($this->teacher)->post($report($this->today), ['content' => 'Unit 2'])->assertSessionHasNoErrors();
        $this->assertSame(2, ClassLessonProgress::count());
    }

    public function test_assistant_cannot_mark_and_other_teacher_cannot_open(): void
    {
        $this->actingAs($this->assistant)->get(route('teacher.lesson-today', ['class' => $this->class->id]))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('can_mark', false)->etc());
        $this->actingAs($this->assistant)->post(route('teacher.lesson-today.taught', $this->class->id), ['class_session_id' => $this->today->id])->assertForbidden();

        $other = $this->userWithRole(Roles::TEACHER_FULLTIME, 'GV Khác');
        $this->actingAs($other)->get(route('teacher.lesson-today', ['class' => $this->class->id]))->assertNotFound();
    }

    /** Thẻ "Chặng đang dạy" liệt kê mọi lớp xem được: GV dạy thay buổi trước bấm "Bài giảng" phải mở được (không lỗi), chỉ không bấm Đã dạy. */
    public function test_substitute_of_past_session_can_open_lesson_from_teaching_stage_card(): void
    {
        $substitute = $this->userWithRole(Roles::TEACHER_FULLTIME, 'GV dạy thay');
        $this->addSession(-2)->update(['teacher_id' => $substitute->id]);

        $this->actingAs($substitute)->get(route('syllabus.teaching-stages'))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->where('assignments.0.class_id', $this->class->id)
                ->where('assignments.0.role_label', 'Học thuật / Quản lý')
                ->where('assignments.0.can_view_order', false)
                ->etc());
        $this->actingAs($substitute)->get(route('teacher.lesson-today', ['class' => $this->class->id]))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->where('classId', $this->class->id)
                ->where('can_mark', false)
                ->etc());
    }

    public function test_test_lesson_has_score_link_and_class_tab_flags_missing_scores(): void
    {
        $this->lesson(1)->update(['lesson_type' => 'mini_test']);
        $this->actingAs($this->teacher)->get(route('teacher.lesson-today', ['class' => $this->class->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('lesson.score_url', fn ($url) => str_contains((string) $url, '/teacher/scores/'.$this->class->id))->etc());

        $this->actingAs($this->teacher)->post(route('teacher.lesson-today.taught', $this->class->id), ['class_session_id' => $this->today->id]);
        $tab = fn () => $this->actingAs($this->staff)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'academic']));
        $tab()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('syllabus.lessons.0.taught_at', fn ($v) => $v !== null)
            ->where('syllabus.lessons.0.missing_score', true)
            ->where('syllabus.lessons.1.planned_date', null)
            ->etc());

        $student = Student::create(['code' => 'HV-QT-9', 'name' => 'HV', 'phone' => '0390000009', 'branch_id' => $this->branch->id, 'status' => 'studying']);
        MiniTestScore::create(['class_id' => $this->class->id, 'student_id' => $student->id, 'user_id' => $this->teacher->id,
            'syllabus_unit_id' => $this->lesson(1)->unit_id, 'name' => 'Mini test Unit 1', 'score' => 8, 'max_score' => 10, 'test_date' => today()]);
        $tab()->assertInertia(fn (AssertableInertia $page) => $page->where('syllabus.lessons.0.missing_score', false)->etc());
    }

    public function test_teacher_view_no_longer_lists_all_stage_lessons(): void
    {
        $this->actingAs($this->teacher)->get(route('syllabus.teacher-view', ['class' => $this->class->id, 'tab' => 'lessons']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('stageUnits')->where('lessonTodayUrl', route('teacher.lesson-today', ['class' => $this->class->id]))->etc())
            ->assertDontSee('Nội dung buổi 2');
    }

    private function lesson(int $no): SyllabusLesson
    {
        return SyllabusLesson::where('curriculum_id', $this->curriculum->id)->where('session_no', $no)->firstOrFail();
    }

    private function addSession(int $days): ClassSession
    {
        return ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->addDays($days)->toDateString(),
            'start_time' => '18:00', 'end_time' => '19:30', 'type' => ClassSession::TYPE_REGULAR, 'status' => 'scheduled', 'teacher_id' => $this->teacher->id]);
    }
}
