<?php

namespace Tests\Feature;

use App\Models\AcademicProject;
use App\Models\AcademicProjectMilestone;
use App\Models\AcademicProjectUpdate;
use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\Penalty;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Sla\AcademicProjectSlaService;
use App\Support\Navigation\SidebarMenu;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Dự án học thuật (soạn sách / chương trình): tạo dự án, mốc + người nhận, chốt tiến độ, thành viên cập nhật tiến độ
 * (khối lượng, link, khó khăn), phản hồi, biên bản trễ deadline theo SLA, báo cáo và lịch hạn mốc ở Tổng quan.
 */
class AcademicProjectTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $lead;

    private User $writer;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 10:00:00');
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CA', 'is_active' => true]);
        $this->lead = $this->makeUser('academic_lead', ['name' => 'Trưởng HT']);
        $this->writer = $this->makeUser('teacher', ['name' => 'Cô Mai']);
        $this->outsider = $this->makeUser('teacher', ['name' => 'Thầy Hùng']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeUser(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['branch_id' => $this->branch->id, 'is_active' => true], $attributes));
        $user->assignRole($role);

        return $user;
    }

    /** @return list<string> */
    private function routesOf(User $user): array
    {
        return collect(app(SidebarMenu::class)->groupsFor($user->fresh()))->flatMap(fn (array $g) => collect($g['items'])->pluck('route'))->all();
    }

    private function project(array $attributes = [], array $milestones = []): AcademicProject
    {
        $project = AcademicProject::create(array_merge([
            'name' => 'Soạn sách lớp 6', 'type' => 'book', 'owner_id' => $this->lead->id, 'status' => 'active',
            'start_date' => '2026-10-01', 'deadline' => '2026-12-31', 'created_by' => $this->lead->id,
        ], $attributes));
        $project->members()->sync([$this->lead->id, $this->writer->id]);
        foreach ($milestones as $i => $m) {
            $project->milestones()->create(array_merge([
                'title' => 'Mốc '.($i + 1), 'assignee_id' => $this->writer->id, 'due_date' => '2026-10-20',
                'target_quantity' => 12, 'unit' => 'unit', 'status' => 'todo', 'sort_order' => $i,
            ], $m));
        }

        return $project->fresh();
    }

    public function test_sidebar_and_pages_follow_permissions(): void
    {
        $leadRoutes = $this->routesOf($this->lead);
        $this->assertContains('academic-projects.index', $leadRoutes);
        $this->assertContains('academic-projects.report', $leadRoutes);

        $teacherRoutes = $this->routesOf($this->writer);
        $this->assertContains('academic-projects.index', $teacherRoutes);
        $this->assertNotContains('academic-projects.report', $teacherRoutes);
        $this->assertContains('academic-projects.report', $this->routesOf($this->makeUser('manager')));
        $this->assertNotContains('academic-projects.index', $this->routesOf($this->makeUser('accountant')));

        $this->actingAs($this->writer)->get(route('academic-projects.index'))->assertOk();
        $this->actingAs($this->writer)->get(route('academic-projects.report'))->assertForbidden();
        $this->actingAs($this->writer)->get(route('academic-projects.create'))->assertForbidden();
        $this->actingAs($this->writer)->post(route('academic-projects.store'), [])->assertForbidden();
        $this->actingAs($this->makeUser('accountant'))->get(route('academic-projects.index'))->assertForbidden();
        $this->actingAs($this->lead)->get(route('academic-projects.create'))->assertOk()->assertSee('Tạo dự án học thuật');
    }

    public function test_lead_creates_project_adds_milestone_and_locks_plan(): void
    {
        $this->actingAs($this->lead)->post(route('academic-projects.store'), [
            'name' => 'Soạn sách Tiếng Anh lớp 6', 'type' => 'book', 'owner_id' => $this->lead->id,
            'member_ids' => [$this->outsider->id], 'start_date' => '2026-10-01', 'deadline' => '2026-12-31',
            'kickoff_notes' => '12 unit, mỗi unit 8 trang', 'kickoff_link' => 'https://drive.google.com/bien-ban',
        ])->assertSessionHasNoErrors();

        $project = AcademicProject::sole();
        $this->assertSame('planning', $project->status);
        $this->assertMatchesRegularExpression('/^DA-2026-\d{3}$/', $project->code);
        $this->assertEqualsCanonicalizing([$this->lead->id, $this->outsider->id], $project->members()->pluck('users.id')->all());
        $this->assertTrue(AdminNotification::where('user_id', $this->outsider->id)->where('type', 'academic_project_member')->exists());

        // Chưa có mốc → chưa chốt được.
        $this->actingAs($this->lead)->post(route('academic-projects.lock', $project->id))->assertSessionHas('error');

        $this->actingAs($this->lead)->post(route('academic-projects.milestones.store', $project->id), [
            'title' => 'Bản thảo Unit 1–4', 'assignee_id' => $this->writer->id, 'due_date' => '2026-10-20',
            'target_quantity' => 4, 'unit' => 'unit',
        ])->assertSessionHasNoErrors();
        $this->assertTrue($project->members()->whereKey($this->writer->id)->exists(), 'Người nhận mốc tự thành thành viên');
        $this->assertTrue(AdminNotification::where('user_id', $this->writer->id)->where('type', 'academic_project_milestone')->exists());

        $this->actingAs($this->lead)->post(route('academic-projects.lock', $project->id))->assertSessionHas('success');
        $project->refresh();
        $this->assertSame('active', $project->status);
        $this->assertNotNull($project->plan_locked_at);

        $this->actingAs($this->lead)->get(route('academic-projects.show', $project->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('AcademicProjects/Show')
                ->where('project.status', 'active')->where('milestones.0.title', 'Bản thảo Unit 1–4')->where('canManage', true));
    }

    public function test_member_updates_own_milestone_with_links_and_difficulties(): void
    {
        $project = $this->project([], [['title' => 'Unit 1–4', 'target_quantity' => 4], ['title' => 'Unit 5–8', 'assignee_id' => $this->lead->id]]);
        [$mine, $other] = $project->milestones->all();

        $this->actingAs($this->writer)->post(route('academic-projects.updates.store', $project->id), [
            'milestone_id' => $other->id, 'content' => 'Làm hộ', 'quantity_done' => 1,
        ])->assertSessionHasErrors('milestone_id');

        $this->actingAs($this->writer)->post(route('academic-projects.updates.store', $project->id), [
            'milestone_id' => $mine->id, 'content' => 'Xong Unit 1, 2', 'links_text' => "ftp://sai\nhttps://drive.google.com/u1",
        ])->assertSessionHasErrors('links_text');

        $this->actingAs($this->writer)->post(route('academic-projects.updates.store', $project->id), [
            'milestone_id' => $mine->id, 'quantity_done' => 2, 'content' => 'Xong Unit 1, 2',
            'links_text' => "https://drive.google.com/u1\nhttps://docs.google.com/u2", 'difficulties' => 'Thiếu tranh minh họa',
        ])->assertSessionHasNoErrors();

        $mine->refresh();
        $this->assertSame(2.0, $mine->done_quantity);
        $this->assertSame('in_progress', $mine->status);
        $this->assertSame(50, $mine->progressPercent());
        $update = AcademicProjectUpdate::sole();
        $this->assertSame(['https://drive.google.com/u1', 'https://docs.google.com/u2'], $update->links);
        $notice = AdminNotification::where('user_id', $this->lead->id)->where('type', 'academic_project_update')->sole();
        $this->assertStringContainsString('Khó khăn', $notice->title);

        // Học thuật phản hồi → người báo nhận thông báo.
        $this->actingAs($this->lead)->post(route('academic-projects.updates.respond', $update->id), ['response' => 'Đã order tranh'])->assertSessionHasNoErrors();
        $this->assertSame('Đã order tranh', $update->fresh()->response);
        $this->assertTrue(AdminNotification::where('user_id', $this->writer->id)->where('type', 'academic_project_response')->exists());
        $this->actingAs($this->writer)->post(route('academic-projects.updates.respond', $update->id), ['response' => 'x'])->assertForbidden();

        // Báo hoàn thành mốc.
        $this->actingAs($this->writer)->post(route('academic-projects.updates.store', $project->id), [
            'milestone_id' => $mine->id, 'quantity_done' => 4, 'content' => 'Xong cả 4 unit', 'marks_complete' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertTrue($mine->fresh()->isDone());
        $this->assertSame(50, $project->fresh()->load('milestones')->progressPercent());
    }

    public function test_members_only_see_their_projects(): void
    {
        $mine = $this->project(['name' => 'Dự án của Mai']);
        $hidden = AcademicProject::create(['name' => 'Dự án khác', 'type' => 'curriculum', 'owner_id' => $this->lead->id, 'status' => 'active']);

        $this->actingAs($this->writer)->get(route('academic-projects.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('projects.total', 1)->where('projects.data.0.name', 'Dự án của Mai'));
        $this->actingAs($this->writer)->get(route('academic-projects.show', $hidden->id))->assertNotFound();
        $this->actingAs($this->outsider)->post(route('academic-projects.updates.store', $mine->id), ['content' => 'x'])->assertNotFound();
        $this->actingAs($this->lead)->get(route('academic-projects.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('projects.total', 2));
    }

    public function test_late_milestone_creates_pending_academic_penalty_once(): void
    {
        SystemSetting::set('sla_academic_project_live_since', '2026-10-01T00:00:00+07:00');
        $project = $this->project([], [
            ['title' => 'Trễ', 'due_date' => '2026-10-06'],
            ['title' => 'Còn hạn', 'due_date' => '2026-10-08'],
            ['title' => 'Xong đúng hạn', 'due_date' => '2026-10-05', 'status' => 'done', 'completed_at' => '2026-10-05 15:00:00'],
            ['title' => 'Hạn trước khi bật', 'due_date' => '2026-09-25'],
        ]);
        $planning = $this->project(['name' => 'Chưa chốt', 'status' => 'planning'], [['title' => 'Kế hoạch', 'due_date' => '2026-10-06']]);

        $result = app(AcademicProjectSlaService::class)->run();
        $this->assertSame(1, $result['academic.milestone_late']);

        $penalty = Penalty::sole();
        $this->assertSame($this->writer->id, $penalty->user_id);
        $this->assertSame('pending', $penalty->status);
        $this->assertSame('academic', $penalty->error_category);
        $this->assertSame('academic.milestone_late', $penalty->auto_source);
        $this->assertSame(0.0, (float) $penalty->amount);
        $this->assertStringContainsString('Trễ', $penalty->violation_type);

        // Chạy lại không lập thêm; trang dự án hiện biên bản.
        app(AcademicProjectSlaService::class)->run();
        $this->assertSame(1, Penalty::count());
        $this->actingAs($this->lead)->get(route('academic-projects.show', $project->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('penalties.0.code', $penalty->code)->where('project.milestones_overdue', 2));
        $this->assertSame(0, Penalty::where('user_id', $this->writer->id)->where('violation_type', 'like', '%Kế hoạch%')->count());
        $this->assertNotNull($planning);
    }

    public function test_reminds_assignee_a_day_before_deadline(): void
    {
        $this->project([], [['title' => 'Ngày mai', 'due_date' => '2026-10-07'], ['title' => 'Tuần sau', 'due_date' => '2026-10-14']]);
        Carbon::setTestNow('2026-10-07 08:00:00');

        $this->assertSame(1, app(AcademicProjectSlaService::class)->run()['academic.milestone_reminder']);
        $this->assertSame(1, AdminNotification::where('user_id', $this->writer->id)->where('type', 'academic_project_due')->count());
        $this->assertSame(0, app(AcademicProjectSlaService::class)->run()['academic.milestone_reminder']);
    }

    public function test_report_and_dashboard_agenda(): void
    {
        $project = $this->project([], [['title' => 'Trễ hạn', 'due_date' => '2026-10-05'], ['title' => 'Hạn tuần này', 'due_date' => '2026-10-09']]);
        AcademicProjectUpdate::create(['academic_project_id' => $project->id, 'user_id' => $this->writer->id, 'content' => 'Đang làm', 'difficulties' => 'Thiếu người duyệt']);

        $this->actingAs($this->lead)->get(route('academic-projects.report'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('AcademicProjects/Report')
                ->where('stats.active', 1)->where('stats.overdue', 1)->where('stats.open_issues', 1)
                ->where('members.0.name', 'Cô Mai')->where('members.0.overdue', 1));

        $this->actingAs($this->lead)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('roleDashboard.agenda.days', fn ($days) => collect($days)
                ->flatMap(fn ($d) => $d['items'])->contains(fn ($i) => $i['kind'] === 'project' && $i['title'] === 'Hạn tuần này')));
    }

    public function test_manager_edits_milestone_and_changes_status(): void
    {
        $project = $this->project([], [['title' => 'Unit 1', 'reminded_at' => now()]]);
        $milestone = $project->milestones->first();

        $this->actingAs($this->lead)->put(route('academic-projects.milestones.update', $milestone->id), [
            'title' => 'Unit 1 (sửa)', 'assignee_id' => $this->outsider->id, 'due_date' => '2026-10-25',
            'target_quantity' => 2, 'unit' => 'unit', 'status' => 'todo',
        ])->assertSessionHasNoErrors();
        $milestone->refresh();
        $this->assertNull($milestone->reminded_at, 'Đổi hạn thì nhắc lại');
        $this->assertTrue($project->members()->whereKey($this->outsider->id)->exists());

        $this->actingAs($this->writer)->put(route('academic-projects.milestones.update', $milestone->id), [])->assertForbidden();

        $this->actingAs($this->lead)->post(route('academic-projects.status', $project->id), ['action' => 'pause'])->assertSessionHas('success');
        $this->assertSame('paused', $project->fresh()->status);
        $this->actingAs($this->lead)->post(route('academic-projects.status', $project->id), ['action' => 'complete'])->assertSessionHas('success');
        $this->assertSame('completed', $project->fresh()->status);
        $this->actingAs($this->writer)->post(route('academic-projects.updates.store', $project->id), ['content' => 'muộn'])->assertSessionHas('error');

        $this->actingAs($this->lead)->delete(route('academic-projects.destroy', $project->id))->assertRedirect(route('academic-projects.index'));
        $this->assertSoftDeleted($project);
    }
}
