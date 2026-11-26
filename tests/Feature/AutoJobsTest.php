<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassScheduleConfig;
use App\Models\ClassSession;
use App\Models\Holiday;
use App\Models\User;
use App\Models\WorkTask;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** classes:generate-sessions + tasks:spawn-recurring (2026-11-26 is a Thursday). */
class AutoJobsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-26 12:00:00'));
        $this->branch = Branch::create(['name' => 'Cơ sở job', 'code' => 'JOB', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeClass(array $attributes = [], bool $config = true): ClassModel
    {
        $class = ClassModel::create($attributes + [
            'name' => 'Lớp job', 'code' => 'JOB-'.ClassModel::count(), 'program' => 'IELTS', 'level' => 'B1',
            'branch_id' => $this->branch->id, 'status' => 'active', 'max_capacity' => 12,
            'start_date' => '2026-10-01', 'end_date' => '2027-01-31',
        ]);
        if ($config) {
            ClassScheduleConfig::create(['class_id' => $class->id, 'slot1_day' => 'Thứ 5', 'slot1_start' => '18:00', 'slot1_end' => '19:30']);
        }

        return $class;
    }

    private function dates(ClassModel $class): array
    {
        return ClassSession::where('class_id', $class->id)->orderBy('date')->get()->map(fn ($s) => $s->date->toDateString())->all();
    }

    public function test_generates_horizon_sessions_skips_branch_holiday_and_is_idempotent(): void
    {
        $class = $this->makeClass();
        $holiday = Holiday::create(['code' => 'HOL-JOB-1', 'name' => 'Nghỉ chi nhánh', 'start_date' => '2026-12-10', 'end_date' => '2026-12-10', 'is_system_wide' => false]);
        $holiday->branches()->sync([$this->branch->id]);

        $this->artisan('classes:generate-sessions')->assertSuccessful();
        $this->assertSame(['2026-11-26', '2026-12-03', '2026-12-17', '2026-12-24'], $this->dates($class));

        $this->artisan('classes:generate-sessions')->assertSuccessful();
        $this->assertCount(4, $this->dates($class));
        $this->assertSame('2027-01-31', $class->fresh()->end_date->toDateString());
    }

    public function test_does_not_recreate_cancelled_session_and_stops_at_class_end_date(): void
    {
        $class = $this->makeClass(['end_date' => '2026-12-03']);
        ClassSession::create(['class_id' => $class->id, 'branch_id' => $this->branch->id, 'date' => '2026-11-26', 'type' => 'regular', 'start_time' => '18:00', 'end_time' => '19:30', 'status' => 'cancelled']);

        $this->artisan('classes:generate-sessions')->assertSuccessful();

        $this->assertSame(['2026-11-26', '2026-12-03'], $this->dates($class));
        $this->assertSame('cancelled', ClassSession::where('class_id', $class->id)->whereDate('date', '2026-11-26')->value('status'));
    }

    public function test_completed_waiting_and_unscheduled_classes_generate_nothing(): void
    {
        $done = $this->makeClass(['status' => 'completed', 'end_date' => '2026-11-01']);
        $waiting = $this->makeClass(['status' => 'upcoming', 'start_date' => '2026-12-15']);
        $noConfig = $this->makeClass([], false);

        $this->artisan('classes:generate-sessions')
            ->expectsOutputToContain("Lớp #{$waiting->id}: bỏ qua — chưa khai giảng")
            ->expectsOutputToContain("Lớp #{$noConfig->id}: bỏ qua — chưa có TKB lặp tuần")
            ->assertSuccessful();

        $this->assertSame(0, ClassSession::count());
        $this->assertSame([], $this->dates($done));
    }

    public function test_session_dry_run_writes_nothing(): void
    {
        $class = $this->makeClass();

        $this->artisan('classes:generate-sessions --dry-run')->expectsOutputToContain('[DRY-RUN]')->assertSuccessful();

        $this->assertSame([], $this->dates($class));
    }

    public function test_session_clashing_with_other_class_room_is_left_out(): void
    {
        $other = $this->makeClass(['room' => 'P1'], false);
        ClassSession::create(['class_id' => $other->id, 'branch_id' => $this->branch->id, 'date' => '2026-12-03', 'type' => 'regular', 'start_time' => '18:00', 'end_time' => '19:30', 'room' => 'P1', 'status' => 'scheduled']);
        $class = $this->makeClass(['room' => 'P1']);

        $this->artisan('classes:generate-sessions')->assertSuccessful();

        $this->assertSame(['2026-11-26', '2026-12-10', '2026-12-17', '2026-12-24'], $this->dates($class));
    }

    private function recurring(string $status, bool $configured = true): WorkTask
    {
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $task = WorkTask::create([
            'title' => 'Kiểm kê', 'creator_id' => $user->id, 'assignee_id' => $user->id, 'branch_id' => $this->branch->id,
            'task_type' => 'recurring', 'time_slot_category' => 'during', 'frequency' => 'weekly',
            'due_date' => '2026-11-19', 'due_time' => '10:00', 'status' => $status,
        ]);
        if ($configured) {
            $task->series->update(['penalty_amount' => 50000, 'configured_at' => now()]);
        }

        return $task;
    }

    public function test_recurring_series_spawns_exactly_once(): void
    {
        $task = $this->recurring('completed');

        $this->artisan('tasks:spawn-recurring')->assertSuccessful();
        $this->artisan('tasks:spawn-recurring')->assertSuccessful();

        $tasks = WorkTask::where('series_id', $task->series_id)->orderBy('id')->get();
        $this->assertCount(2, $tasks);
        $this->assertSame('2026-11-26', $tasks[1]->due_date->toDateString());
        $this->assertSame('new', $tasks[1]->status);
    }

    public function test_open_occurrence_unconfigured_series_and_dry_run_do_not_spawn(): void
    {
        $open = $this->recurring('overdue');
        $unconfigured = $this->recurring('completed', false);
        $done = $this->recurring('canceled');

        $this->artisan('tasks:spawn-recurring --dry-run')->expectsOutputToContain('[DRY-RUN] Sẽ tạo 1')->assertSuccessful();
        $this->assertSame(1, WorkTask::where('series_id', $done->series_id)->count());

        $this->artisan('tasks:spawn-recurring')->assertSuccessful();

        $this->assertSame(1, WorkTask::where('series_id', $open->series_id)->count());
        $this->assertSame(1, WorkTask::where('series_id', $unconfigured->series_id)->count());
        $this->assertSame(2, WorkTask::where('series_id', $done->series_id)->count());
    }

    public function test_jobs_are_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->mapWithKeys(fn ($e) => [$e->command => $e->expression]);

        $this->assertSame('30 5 * * *', $events->first(fn ($x, $c) => str_contains($c, 'classes:generate-sessions')));
        $this->assertSame('45 5 * * *', $events->first(fn ($x, $c) => str_contains($c, 'tasks:spawn-recurring')));
    }
}
