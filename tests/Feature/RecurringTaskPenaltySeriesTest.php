<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Penalty;
use App\Models\User;
use App\Models\WorkTask;
use App\Models\WorkTaskSeries;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RecurringTaskPenaltySeriesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00'));
        $this->branch = Branch::create(['name' => 'Cơ sở việc lặp', 'code' => 'VL', 'is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->admin->assignRole('admin');
        $this->staff = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->staff->assignRole('assistant');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function task(array $attributes = []): WorkTask
    {
        return WorkTask::create($attributes + [
            'title' => 'Kiểm kê hàng tuần',
            'creator_id' => $this->admin->id,
            'assignee_id' => $this->staff->id,
            'branch_id' => $this->branch->id,
            'task_type' => 'recurring',
            'time_slot_category' => 'during',
            'frequency' => 'weekly',
            'due_date' => today()->toDateString(),
            'due_time' => '10:00',
            'status' => 'new',
        ]);
    }

    private function configure(WorkTask $task, mixed $amount = 50000): void
    {
        $this->actingAs($this->admin)
            ->post(route('tasks.series.update', $task->series_id), ['penalty_amount' => $amount])
            ->assertSessionHasNoErrors()->assertRedirect(route('tasks.series.index'));
    }

    public function test_completing_an_unconfigured_series_does_not_spawn(): void
    {
        $task = $this->task();
        $this->assertNotNull($task->series_id);
        $this->assertFalse($task->series->isConfigured());
        $task->update(['status' => 'completed']);

        $this->assertSame(1, WorkTask::where('series_id', $task->series_id)->count());
        $this->assertNull($task->spawnNextOccurrence());
        $this->assertNull($task->series->resume());
    }

    public function test_configuring_resumes_once_on_the_first_period_on_or_after_today(): void
    {
        // Three missed weekly periods must produce only today's occurrence.
        $task = $this->task(['due_date' => today()->subWeeks(3), 'status' => 'completed']);
        $this->configure($task);

        $next = WorkTask::where('series_id', $task->series_id)->whereKeyNot($task->id)->sole();
        $this->assertSame('2026-11-11', $next->due_date->toDateString());
        $this->assertSame('new', $next->status);
        $this->assertTrue($task->series->fresh()->isConfigured());
        $this->assertSame($this->admin->id, $task->series->fresh()->configured_by);

        $this->configure($task);
        $this->assertSame(2, WorkTask::where('series_id', $task->series_id)->count());
    }

    public function test_resume_uses_latest_occurrence_and_shared_daily_weekly_monthly_dates(): void
    {
        foreach ([
            ['daily', '2026-11-08', '2026-11-11'],
            ['weekly', '2026-10-20', '2026-11-17'],
            ['monthly', '2026-08-31', '2026-11-30'],
        ] as [$frequency, $dueDate, $expected]) {
            $old = $this->task(['frequency' => $frequency, 'due_date' => '2026-01-01', 'status' => 'canceled']);
            $latest = $this->task(['series_id' => $old->series_id, 'frequency' => $frequency, 'due_date' => $dueDate, 'status' => 'completed']);
            $this->configure($latest, 0);
            $next = WorkTask::where('series_id', $old->series_id)->latest('id')->firstOrFail();
            $this->assertSame($expected, $next->due_date->toDateString());
            $this->assertSame(3, WorkTask::where('series_id', $old->series_id)->count());
        }
    }

    public function test_configure_never_adds_an_occurrence_while_any_nonterminal_occurrence_exists(): void
    {
        foreach (['new', 'in_progress', 'overdue', 'blocked', 'pending_confirmation'] as $status) {
            $open = $this->task(['status' => $status]);
            $latest = $this->task(['series_id' => $open->series_id, 'status' => 'completed']);
            $this->configure($latest);
            $this->assertSame(2, WorkTask::where('series_id', $open->series_id)->count());
        }
    }

    public function test_a_stale_completion_cannot_spawn_a_missed_period_after_resume(): void
    {
        $task = $this->task(['due_date' => today()->subWeeks(3), 'status' => 'completed']);
        $this->configure($task);

        // A completion hook delayed until after configuration must not branch from the old occurrence.
        $this->assertNull($task->spawnNextOccurrence());
        $this->assertSame(2, WorkTask::where('series_id', $task->series_id)->count());
        $this->assertSame(0, WorkTask::where('series_id', $task->series_id)->whereKeyNot($task->id)
            ->whereDate('due_date', '<', today())->count());
    }

    public function test_configured_completion_spawns_the_next_occurrence_in_the_same_series(): void
    {
        $task = $this->task(['frequency' => 'monthly', 'due_date' => '2026-01-31']);
        $this->configure($task, 0);
        $task->update(['status' => 'completed']);

        $next = WorkTask::where('series_id', $task->series_id)->whereKeyNot($task->id)->sole();
        $this->assertSame('2026-02-28', $next->due_date->toDateString());
        $this->assertSame($task->series_id, $next->series_id);
        $this->assertNull($task->spawnNextOccurrence());
    }

    public function test_distinct_series_with_identical_titles_do_not_block_each_other(): void
    {
        $first = $this->task();
        $second = $this->task();
        foreach ([$first, $second] as $task) {
            $this->configure($task, 0);
            $task->update(['status' => 'completed']);
            $this->assertSame(2, WorkTask::where('series_id', $task->series_id)->count());
        }
    }

    public function test_store_ignores_recurring_penalty_input_but_keeps_one_off_penalties(): void
    {
        foreach ([
            ['phat_khi_qua_han' => '1', 'muc_phat_qua_han' => 50000],
            ['phat_khi_qua_han' => ['invalid'], 'muc_phat_qua_han' => ['invalid']],
            ['phat_khi_qua_han' => '1'],
        ] as $penalty) {
            $this->actingAs($this->admin)->post(route('tasks.store'), $penalty + [
                'taskTitle' => 'Việc lặp mới', 'assignee' => $this->staff->id,
                'dueDate' => today()->addDay()->toDateString(), 'taskType' => 'recurring', 'frequency' => 'weekly',
            ])->assertSessionHasNoErrors();
        }
        foreach (WorkTask::all() as $task) {
            $this->assertFalse($task->phat_khi_qua_han);
            $this->assertNull($task->muc_phat_qua_han);
            $this->assertFalse($task->series->isConfigured());
            $this->assertNull($task->series->penalty_amount);
        }

        $this->actingAs($this->admin)->post(route('tasks.store'), [
            'taskTitle' => 'Việc phát sinh', 'assignee' => $this->staff->id,
            'dueDate' => today()->addDay()->toDateString(), 'taskType' => 'one-time',
            'phat_khi_qua_han' => '1', 'muc_phat_qua_han' => 50000,
        ])->assertSessionHasNoErrors();
        $oneOff = WorkTask::where('task_type', 'one_time')->sole();
        $this->assertTrue($oneOff->phat_khi_qua_han);
        $this->assertEquals(50000, $oneOff->muc_phat_qua_han);
        $this->assertNull($oneOff->series_id);
    }

    public function test_series_penalty_is_fixed_for_successive_occurrences_and_never_duplicated(): void
    {
        $task = $this->task(['due_date' => '2026-11-10', 'phat_khi_qua_han' => true, 'muc_phat_qua_han' => 90000]);
        $this->configure($task);
        $this->artisan('tasks:mark-overdue')->assertSuccessful();
        $firstPenalty = Penalty::withoutViewerScope()->where('work_task_id', $task->id)->sole();
        $this->assertSame('fined', $firstPenalty->status);
        $this->assertEquals(50000, $firstPenalty->amount);

        $firstPenalty->update(['status' => 'cancelled']);
        $task->update(['status' => 'in_progress']);
        $this->artisan('tasks:mark-overdue')->assertSuccessful();
        $this->assertSame(1, Penalty::withoutViewerScope()->where('work_task_id', $task->id)->count());
        $task->update(['status' => 'completed']);
        $next = WorkTask::where('series_id', $task->series_id)->whereKeyNot($task->id)->sole();
        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00'));
        $this->artisan('tasks:mark-overdue')->assertSuccessful();
        $this->artisan('tasks:mark-overdue')->assertSuccessful();
        $secondPenalty = Penalty::withoutViewerScope()->where('work_task_id', $next->id)->sole();
        $this->assertSame('fined', $secondPenalty->status);
        $this->assertEquals(50000, $secondPenalty->amount);
        $this->assertSame(2, Penalty::withoutViewerScope()->where('auto_source', 'task_overdue')->count());
    }

    public function test_series_amount_is_read_live_instead_of_occurrence_penalty_fields(): void
    {
        $task = $this->task(['due_date' => today()->subDay(), 'phat_khi_qua_han' => false]);
        $this->configure($task, 10000);
        $this->configure($task, 50000);
        $this->artisan('tasks:mark-overdue')->assertSuccessful();
        $this->assertEquals(50000, Penalty::withoutViewerScope()->where('work_task_id', $task->id)->sole()->amount);
    }

    public function test_unconfigured_and_zero_amount_series_do_not_penalize_even_with_legacy_flags(): void
    {
        $paused = $this->task(['due_date' => today()->subDay(), 'phat_khi_qua_han' => true, 'muc_phat_qua_han' => 50000]);
        $zero = $this->task(['due_date' => today()->subDay(), 'phat_khi_qua_han' => true, 'muc_phat_qua_han' => 50000]);
        $this->configure($zero, 0);
        $this->assertFalse($paused->series->isConfigured());
        $this->assertTrue($zero->series->fresh()->isConfigured());
        $this->artisan('tasks:mark-overdue')->assertSuccessful();
        $this->assertSame('overdue', $paused->fresh()->status);
        $this->assertSame('overdue', $zero->fresh()->status);
        $this->assertSame(0, Penalty::withoutViewerScope()->count());
    }

    public function test_series_penalties_keep_foreign_teacher_and_sla_guards(): void
    {
        $foreign = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $foreign->assignRole('foreign_teacher');
        foreach ([['assignee_id' => $foreign->id], ['sla_rule_key' => 'crm.test_result'], ['care_milestone' => 1]] as $attributes) {
            $task = $this->task($attributes + ['due_date' => today()->subDay()]);
            $this->configure($task);
        }
        $this->artisan('tasks:mark-overdue')->assertSuccessful();
        $this->assertSame(0, Penalty::withoutViewerScope()->where('auto_source', 'task_overdue')->count());
    }

    public function test_penalty_amount_must_be_a_scalar_number_in_range(): void
    {
        $task = $this->task();
        foreach ([null, '', 'abc', [50000], ['first' => 50000], -1, 100000001] as $amount) {
            $this->actingAs($this->admin)->post(route('tasks.series.update', $task->series_id), ['penalty_amount' => $amount])
                ->assertSessionHasErrors('penalty_amount');
            $this->assertFalse($task->series->fresh()->isConfigured());
        }
        $this->configure($task, '100000000');
        $this->assertEquals(100000000, $task->series->fresh()->penalty_amount);
    }

    public function test_backfill_groups_legacy_recurring_rows_across_chunks_and_is_idempotent(): void
    {
        $base = [
            'title' => 'Việc lặp cũ', 'creator_id' => $this->admin->id, 'assignee_id' => $this->staff->id,
            'task_type' => 'recurring', 'frequency' => 'weekly', 'status' => 'completed',
            'due_date' => '2026-10-01', 'created_at' => now(), 'updated_at' => now(),
        ];
        for ($i = 0; $i < 205; $i++) {
            DB::table('work_tasks')->insert($base);
        }
        DB::table('work_tasks')->insert(['frequency' => 'daily'] + $base);
        DB::table('work_tasks')->insert(['title' => 'Việc khác'] + $base);
        DB::table('work_tasks')->insert(['assignee_id' => $this->admin->id] + $base);
        DB::table('work_tasks')->insert(['frequency' => null] + $base);
        $oneOffId = DB::table('work_tasks')->insertGetId(['task_type' => 'one_time'] + $base);

        $migration = require database_path('migrations/2026_12_11_090000_create_work_task_series.php');
        $migration->backfill();
        $this->assertSame(5, WorkTaskSeries::count());
        $this->assertSame(0, WorkTaskSeries::whereNotNull('configured_at')->count());
        $this->assertSame(0, WorkTaskSeries::whereNotNull('penalty_amount')->count());
        $this->assertSame(1, WorkTask::where('title', $base['title'])->where('assignee_id', $this->staff->id)
            ->where('frequency', 'weekly')->distinct()->count('series_id'));
        $this->assertNull(DB::table('work_tasks')->where('id', $oneOffId)->value('series_id'));
        $this->assertSame(0, WorkTask::where('task_type', 'recurring')->whereNull('series_id')->count());
        $migration->backfill();
        $this->assertSame(5, WorkTaskSeries::count());
    }

    public function test_configuration_routes_require_assign_permission_and_show_the_latest_occurrence(): void
    {
        $task = $this->task();
        $latest = $this->task(['series_id' => $task->series_id, 'title' => 'Tiêu đề mới nhất', 'frequency' => 'daily']);
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo('work_task.view');
        $this->actingAs($viewer)->get(route('tasks.series.index'))->assertForbidden();
        $this->actingAs($viewer)->post(route('tasks.series.update', $task->series_id), ['penalty_amount' => 50000])->assertForbidden();
        $this->actingAs($this->admin)->get(route('tasks.series.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Tasks/PenaltySeries')
                ->has('series.data', 1)->where('series.data.0.title', $latest->title)
                ->where('series.data.0.assignee', $this->staff->name)
                ->where('series.data.0.frequency', 'Hàng ngày')->where('series.data.0.penalty_amount', null));
        $this->configure($task);
        $this->actingAs($this->admin)->get(route('tasks.series.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('series.data.0.penalty_amount', 50000)
                ->where('series.data.0.configured', true));
        $activity = Activity::where('subject_type', WorkTaskSeries::class)->where('subject_id', $task->series_id)
            ->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertEquals(50000, $activity->properties['attributes']['penalty_amount']);
        $this->assertSame($this->admin->id, $activity->causer_id);
    }

    public function test_series_list_and_update_follow_the_latest_occurrence_viewer_scope(): void
    {
        $manager = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $manager->givePermissionTo(['work_task.view', 'work_task.assign', 'work_task.scope_branch']);
        $visible = $this->task();
        $hidden = $this->task();
        $otherBranch = Branch::create(['name' => 'Cơ sở khác', 'code' => 'KHAC', 'is_active' => true]);
        $outsider = User::factory()->create(['branch_id' => $otherBranch->id]);
        $this->task(['series_id' => $hidden->series_id, 'branch_id' => $otherBranch->id,
            'creator_id' => $outsider->id, 'assignee_id' => $outsider->id]);

        $this->actingAs($manager)->get(route('tasks.series.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('series.data', 1)->where('series.data.0.id', $visible->series_id));
        $this->actingAs($manager)->post(route('tasks.series.update', $hidden->series_id), ['penalty_amount' => 50000])->assertNotFound();
        $this->assertFalse($hidden->series->fresh()->isConfigured());
    }
}
