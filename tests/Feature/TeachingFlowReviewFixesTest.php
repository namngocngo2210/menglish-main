<?php

namespace Tests\Feature;

use App\Models\ClassLessonProgress;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\CourseLevel;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusLesson;
use App\Services\SessionLessonService;
use App\Services\Syllabus\ClassSyllabus;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** Sửa sau review giai đoạn 3 (docs/kho-syllabus.md §8): mỗi test tái hiện một lỗi review tìm ra. */
class TeachingFlowReviewFixesTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private SyllabusAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
        $this->assignment = $this->openStage($this->stage1, ['start_date' => today()->subDays(20), 'opened_at' => today()->subDays(20), 'confirmed_at' => now()]);
    }

    public function test_index_names_fit_mariadb_limit(): void
    {
        foreach (['class_lesson_progress', 'class_lesson_overrides', 'syllabus_drive_grants'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                if (! str_starts_with($index['name'], 'sqlite_')) {
                    $this->assertLessThanOrEqual(64, strlen($index['name']), "{$table}: {$index['name']}");
                }
            }
        }
    }

    public function test_sessions_after_all_lessons_are_marked_as_review_and_banner_clears(): void
    {
        foreach (range(1, 6) as $i) {
            $this->mark($i, $this->addSession(-20 + $i));
        }
        $review = $this->addSession(-3);
        $today = $this->addSession(0);

        $this->actingAs($this->teacher)->get(route('teacher.lesson-today', ['class' => $this->class->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('finished', true)->where('can_mark', true)->where('unmarked.0.id', $review->id)->etc());
        $this->actingAs($this->teacher)->post(route('teacher.lesson-today.taught', $this->class->id), ['class_session_id' => $review->id])->assertSessionHasNoErrors();
        $this->actingAs($this->teacher)->post(route('teacher.lesson-today.taught', $this->class->id), ['class_session_id' => $today->id])->assertSessionHasNoErrors();

        $this->assertSame(2, ClassLessonProgress::where('kind', ClassLessonProgress::KIND_EXTRA)->count());
        $this->assertCount(0, app(ClassSyllabus::class)->timeline($this->assignment)['unmarked_sessions']);
    }

    public function test_marking_must_follow_session_order_and_records_session_date(): void
    {
        $older = $this->addSession(-4);
        $newer = $this->addSession(-2);

        $this->actingAs($this->teacher)->post(route('teacher.lesson-today.taught', $this->class->id), ['class_session_id' => $newer->id])
            ->assertSessionHasErrors('class_session_id');
        $this->actingAs($this->teacher)->post(route('teacher.lesson-today.taught', $this->class->id), ['class_session_id' => $older->id])->assertSessionHasNoErrors();

        $mark = ClassLessonProgress::sole();
        $this->assertSame($older->date->toDateString(), $mark->taught_at->toDateString(), 'Ngày Đã dạy = ngày buổi học, không phải lúc bấm.');
    }

    public function test_default_class_is_one_with_session_today(): void
    {
        $other = ClassModel::create(['code' => 'QT-00', 'name' => 'Lớp AA (đứng đầu theo tên)', 'branch_id' => $this->branch->id, 'status' => 'active', 'teacher_id' => $this->teacher->id]);
        SyllabusAssignment::create(['class_id' => $other->id, 'curriculum_id' => $this->curriculum->id, 'stage_id' => $this->stage1->id, 'stage_name' => $this->stage1->label,
            'assigned_chapters' => $this->stage1->label, 'user_id' => $this->teacher->id, 'status' => 'in_progress', 'opened_at' => now(), 'start_date' => today()]);
        $this->addSession(0);

        $this->actingAs($this->teacher)->get(route('teacher.lesson-today'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('classId', $this->class->id)->etc());
    }

    public function test_lessons_are_planned_before_extra_sessions_consistently(): void
    {
        $this->assignment->update(['extra_sessions' => 1]);
        $next = $this->addSession(1);
        $this->addSession(3);

        $rows = app(ClassSyllabus::class)->timeline($this->assignment)['rows'];
        $this->assertSame($next->id, $rows[0]['planned_session_id'], 'Buổi giãn không chiếm buổi gần nhất.');
        $this->assertSame('Bài 1', app(SessionLessonService::class)->lessonsFor(collect([$next]))[$next->id]['title']);
    }

    public function test_session_lesson_mapping_is_batched(): void
    {
        foreach (range(2, 6) as $n) {
            $class = ClassModel::create(['code' => "QT-B{$n}", 'name' => "Lớp B{$n}", 'branch_id' => $this->branch->id, 'status' => 'active', 'teacher_id' => $this->teacher->id]);
            SyllabusAssignment::create(['class_id' => $class->id, 'curriculum_id' => $this->curriculum->id, 'stage_id' => $this->stage1->id, 'stage_name' => $this->stage1->label,
                'assigned_chapters' => $this->stage1->label, 'user_id' => $this->teacher->id, 'status' => 'in_progress', 'opened_at' => now(), 'start_date' => today()]);
            ClassSession::create(['class_id' => $class->id, 'branch_id' => $this->branch->id, 'date' => today()->addDay()->toDateString(), 'start_time' => '18:00', 'end_time' => '19:30', 'type' => 'regular', 'status' => 'scheduled']);
        }
        $sessions = ClassSession::all();

        DB::enableQueryLog();
        app(SessionLessonService::class)->lessonsFor($sessions);
        $this->assertLessThanOrEqual(12, count(DB::getQueryLog()), 'Không chạy timeline đầy đủ cho từng lớp.');
        DB::disableQueryLog();
    }

    public function test_closed_stage_sessions_keep_lesson_content(): void
    {
        $this->assignment->update(['status' => SyllabusAssignment::STATUS_CLOSED, 'closed_at' => today()->subDays(2)]);
        $old = $this->addSession(-10);
        $this->class->update(['level' => 'QT-LV']);
        CourseLevel::create(['code' => 'QT-LV', 'name' => 'QT', 'level_group' => 'QT', 'target' => 'QT', 'duration' => '36 buổi', 'lessons_count' => 36,
            'syllabus_curriculum_id' => $this->curriculum->id, 'is_active' => true]);
        SyllabusAssignment::create(['class_id' => $this->class->id, 'curriculum_id' => $this->curriculum->id, 'stage_id' => $this->stage2->id, 'stage_name' => $this->stage2->label,
            'assigned_chapters' => $this->stage2->label, 'user_id' => $this->teacher->id, 'status' => 'in_progress', 'opened_at' => today()->subDay(), 'start_date' => today()->subDay()]);

        $this->assertSame('Bài 1', app(SessionLessonService::class)->lessonsFor(collect([$old]))[$old->id]['title'] ?? null);
    }

    public function test_teacher_sees_class_tab_progress_without_lesson_content(): void
    {
        SyllabusLesson::where('curriculum_id', $this->curriculum->id)->where('session_no', 2)->update(['content' => 'Nội dung bí mật buổi 2']);

        $this->actingAs($this->teacher)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'syllabus']))
            ->assertOk()->assertDontSee('Nội dung bí mật buổi 2')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('syllabus.lessons.1.data', null)->where('syllabus.lessons.1.title', 'Bài 2')->etc());
        $this->actingAs($this->staff)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'syllabus']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('syllabus.lessons.1.data.content', 'Nội dung bí mật buổi 2')->etc());
    }

    public function test_approver_screen_distinguishes_postpone_requests(): void
    {
        SyllabusAdjustmentRequest::create(['class_id' => $this->class->id, 'syllabus_assignment_id' => $this->assignment->id, 'user_id' => $this->teacher->id,
            'request_type' => 'Dời tiến độ 2 buổi', 'kind' => 'postpone', 'reason' => 'Ôn lại', 'extra_sessions' => 2, 'status' => 'pending']);
        $admin = $this->userWithRole(Roles::ADMIN, 'Admin');

        $this->actingAs($admin)->get(route('syllabus.adjustment-requests', ['request' => SyllabusAdjustmentRequest::sole()->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('requests.data.0.kind', 'postpone')->where('requests.data.0.kind_label', 'Dời tiến độ')->etc())
            ->assertSee('không thêm buổi vào TKB');
    }

    private function addSession(int $days): ClassSession
    {
        return ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->addDays($days)->toDateString(),
            'start_time' => '18:00', 'end_time' => '19:30', 'type' => ClassSession::TYPE_REGULAR, 'status' => 'scheduled', 'teacher_id' => $this->teacher->id]);
    }

    private function mark(int $sessionNo, ClassSession $session): void
    {
        ClassLessonProgress::create(['syllabus_assignment_id' => $this->assignment->id, 'class_id' => $this->class->id,
            'syllabus_lesson_id' => SyllabusLesson::where('curriculum_id', $this->curriculum->id)->where('session_no', $sessionNo)->value('id'),
            'class_session_id' => $session->id, 'kind' => 'lesson', 'taught_by' => $this->teacher->id, 'taught_at' => $session->date]);
    }
}
