<?php

namespace Tests\Feature;

use App\Models\ClassLessonProgress;
use App\Models\ClassSession;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusLesson;
use App\Services\SessionLessonService;
use App\Services\Syllabus\ClassSyllabus;
use App\Services\Syllabus\ProgressBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** Tiến độ chặng chỉ chạy khi GV bấm Đã dạy; lịch dự kiến tự dời theo TKB (docs/kho-syllabus.md §8.1–8.2). */
class ClassLessonProgressTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private SyllabusAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
        $this->assignment = $this->openStage($this->stage1, ['start_date' => today()->subDays(10), 'opened_at' => today()->subDays(10), 'confirmed_at' => now()]);
    }

    public function test_progress_only_moves_when_marked(): void
    {
        $past = $this->addSession(-3);
        $this->addSession(-1);

        $timeline = app(ClassSyllabus::class)->timeline($this->assignment);
        $this->assertSame(1, $timeline['current']['lesson']->session_no, 'Buổi đã qua nhưng chưa bấm Đã dạy không làm tiến độ chạy.');
        $this->assertSame(0, $timeline['taught']);

        $this->mark(1, $past);

        $timeline = app(ClassSyllabus::class)->timeline($this->assignment);
        $this->assertSame(2, $timeline['current']['lesson']->session_no);
        $this->assertSame(1, $timeline['taught']);
        $this->assertSame($past->id, $timeline['rows'][0]['taught_session_id']);
    }

    public function test_planned_dates_skip_cancelled_sessions_and_use_makeup(): void
    {
        $this->addSession(1);
        $this->addSession(2, status: 'cancelled');
        $this->addSession(3, type: ClassSession::TYPE_MAKEUP);
        $this->addSession(4);

        $rows = app(ClassSyllabus::class)->timeline($this->assignment)['rows'];

        $this->assertSame([today()->addDays(1)->toDateString(), today()->addDays(3)->toDateString(), today()->addDays(4)->toDateString(), null, null, null],
            $rows->map(fn ($r) => $r['planned_date']?->toDateString())->all());
    }

    public function test_extra_sessions_are_planned_before_remaining_lessons(): void
    {
        $this->assignment->update(['extra_sessions' => 1]);
        foreach ([1, 3, 4] as $d) {
            $this->addSession($d);
        }

        $timeline = app(ClassSyllabus::class)->timeline($this->assignment);

        $this->assertSame(1, $timeline['extra_left']);
        $this->assertSame([today()->addDays(3)->toDateString(), today()->addDays(4)->toDateString()],
            $timeline['rows']->take(2)->map(fn ($r) => $r['planned_date']?->toDateString())->all());
    }

    public function test_unmarked_past_sessions_are_listed(): void
    {
        $this->addSession(-20); // trước ngày bắt đầu chặng
        $marked = $this->addSession(-5);
        $unmarked = $this->addSession(-2);
        $this->addSession(-1, status: 'cancelled');
        $this->mark(1, $marked);

        $this->assertSame([$unmarked->id], app(ClassSyllabus::class)->timeline($this->assignment)['unmarked_sessions']->pluck('id')->all());
    }

    public function test_session_lesson_service_maps_past_by_progress_and_future_by_plan(): void
    {
        $past = $this->addSession(-2);
        $future = $this->addSession(2);
        $this->mark(1, $past);

        $lessons = app(SessionLessonService::class)->lessonsFor(collect([$past, $future]));

        $this->assertSame('Bài 1', $lessons[$past->id]['title']);
        $this->assertSame('Bài 2', $lessons[$future->id]['title']);
    }

    public function test_backfill_marks_lessons_taught_from_past_sessions_capped_at_stage_size(): void
    {
        foreach (range(1, 8) as $i) {
            $this->addSession(-9 + $i - 1);
        }
        $this->addSession(-12); // trước ngày mở chặng

        app(ProgressBackfill::class)->run();
        app(ProgressBackfill::class)->run();

        $rows = ClassLessonProgress::where('syllabus_assignment_id', $this->assignment->id)->get();
        $this->assertCount(6, $rows, 'Không vượt số buổi của chặng.');
        $this->assertSame(6, $rows->pluck('class_session_id')->unique()->count());
        $this->assertNull(app(ClassSyllabus::class)->timeline($this->assignment)['current']);
    }

    private function addSession(int $days, string $type = ClassSession::TYPE_REGULAR, string $status = 'scheduled'): ClassSession
    {
        return ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->addDays($days)->toDateString(),
            'start_time' => '18:00', 'end_time' => '19:30', 'type' => $type, 'status' => $status]);
    }

    private function mark(int $sessionNo, ClassSession $session): void
    {
        ClassLessonProgress::create([
            'syllabus_assignment_id' => $this->assignment->id, 'class_id' => $this->class->id,
            'syllabus_lesson_id' => SyllabusLesson::where('curriculum_id', $this->curriculum->id)->where('session_no', $sessionNo)->value('id'),
            'class_session_id' => $session->id, 'kind' => ClassLessonProgress::KIND_LESSON, 'taught_by' => $this->teacher->id, 'taught_at' => $session->date,
        ]);
    }
}
