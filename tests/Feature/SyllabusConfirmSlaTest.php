<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Penalty;
use App\Models\SlaEvent;
use App\Models\SyllabusAssignment;
use App\Models\SystemSetting;
use App\Services\Sla\TeacherSlaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** SLA gv.syllabus_confirm: GV XÁC NHẬN chặng chậm nhất 1 giờ trước buổi đầu tiên của chặng (docs/luong-giao-vien.md). */
class SyllabusConfirmSlaTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private Carbon $day;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->day = today()->addDays(3);
        $this->travelTo($this->day->copy()->setTime(9, 0));
        $this->buildSyllabusWorkflow();
        SystemSetting::set('sla_teacher_live_since', now()->subDays(10)->toIso8601String());
    }

    public function test_confirming_before_the_deadline_closes_the_milestone_without_penalty(): void
    {
        $assignment = $this->assign(now()->subDays(2));
        $this->classSession(0, '18:00');
        $assignment->update(['confirmed_at' => $this->day->copy()->setTime(16, 30)]);

        $this->runAt($this->day->copy()->setTime(18, 0));

        $this->assertSame(0, $this->penalties()->count());
        $this->assertNotNull(SlaEvent::where('rule_key', 'gv.syllabus_confirm')->where('subject_id', $assignment->id)->value('resolved_at'));
    }

    public function test_unconfirmed_after_deadline_creates_one_penalty_for_the_teacher(): void
    {
        $assignment = $this->assign(now()->subDays(2));
        $this->classSession(0, '18:00');

        // Chưa tới hạn (17:00) → chưa phạt.
        $this->runAt($this->day->copy()->setTime(16, 59));
        $this->assertSame(0, $this->penalties()->count());

        $this->runAt($this->day->copy()->setTime(17, 15));
        $penalty = $this->penalties()->sole();
        $this->assertSame($this->teacher->id, $penalty->user_id);
        $this->assertSame($this->day->copy()->setTime(17, 0)->toDateTimeString(), $penalty->violation_at->toDateTimeString());
        $this->assertStringContainsString($this->class->name, $penalty->violation_type);

        // Chạy lại / xác nhận muộn sau đó không lập thêm biên bản.
        $assignment->update(['confirmed_at' => now()]);
        $this->runAt($this->day->copy()->setTime(20, 0));
        $this->assertSame(1, $this->penalties()->count());
    }

    public function test_stage_assigned_after_the_cutoff_uses_the_next_session(): void
    {
        $this->assign($this->day->copy()->setTime(17, 30));
        $this->classSession(0, '18:00');
        $this->classSession(2, '18:00');

        $this->runAt($this->day->copy()->addDay()->setTime(9, 0));
        $this->assertSame(0, $this->penalties()->count());

        $this->runAt($this->day->copy()->addDays(2)->setTime(17, 5));
        $this->assertSame($this->day->copy()->addDays(2)->setTime(17, 0)->toDateTimeString(), $this->penalties()->sole()->violation_at->toDateTimeString());
    }

    public function test_deadlines_before_the_rule_went_live_are_not_penalized(): void
    {
        SystemSetting::set('sla_teacher_live_since', $this->day->copy()->setTime(18, 0)->toIso8601String());
        $this->assign(now()->subDays(2));
        $this->classSession(0, '18:00');

        $this->runAt($this->day->copy()->setTime(19, 0));

        $this->assertSame(0, $this->penalties()->count());
    }

    private function assign(Carbon $assignedAt): SyllabusAssignment
    {
        return $this->openStage($this->stage1, ['start_date' => $this->day->toDateString(), 'assigned_at' => $assignedAt, 'opened_at' => $assignedAt]);
    }

    private function classSession(int $days, string $start): ClassSession
    {
        return ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => $this->day->copy()->addDays($days)->toDateString(),
            'start_time' => $start, 'end_time' => '19:30', 'type' => ClassSession::TYPE_REGULAR, 'status' => 'scheduled', 'teacher_id' => $this->teacher->id]);
    }

    private function runAt(Carbon $at): void
    {
        $this->travelTo($at);
        app(TeacherSlaService::class)->run();
    }

    private function penalties()
    {
        return Penalty::withoutViewerScope()->where('auto_source', 'gv.syllabus_confirm');
    }
}
