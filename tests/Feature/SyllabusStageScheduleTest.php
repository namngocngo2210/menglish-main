<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Services\Syllabus\StageSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** Ngày dự kiến kết thúc chặng = buổi học thứ N của lớp từ ngày bắt đầu theo TKB (docs/kho-syllabus.md §7.3). */
class SyllabusStageScheduleTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
    }

    public function test_end_date_is_nth_active_session_from_start(): void
    {
        $this->addSession('2026-11-02');
        $this->addSession('2026-11-04', status: 'cancelled');
        $this->addSession('2026-11-06');
        $this->addSession('2026-11-07', type: ClassSession::TYPE_SUPPORT);
        $this->addSession('2026-11-09', type: ClassSession::TYPE_MAKEUP);
        $this->addSession('2026-11-11');
        $this->addSession('2026-11-13');
        $this->addSession('2026-10-30'); // trước ngày bắt đầu

        $schedule = app(StageSchedule::class);

        $this->assertSame('2026-11-13', $schedule->endDate($this->class, Carbon::parse('2026-11-01'), 5)?->toDateString());
        $this->assertSame(['2026-11-02', '2026-11-06', '2026-11-09', '2026-11-11', '2026-11-13'],
            $schedule->sessionDates($this->class, Carbon::parse('2026-11-01'))->map->toDateString()->all());
    }

    public function test_returns_null_when_schedule_too_short(): void
    {
        $this->addSession('2026-11-02');

        $this->assertNull(app(StageSchedule::class)->endDate($this->class, Carbon::parse('2026-11-01'), 3));
    }

    private function addSession(string $date, string $type = ClassSession::TYPE_REGULAR, string $status = 'scheduled'): void
    {
        ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => $date,
            'start_time' => '18:00', 'end_time' => '19:30', 'type' => $type, 'status' => $status]);
    }
}
