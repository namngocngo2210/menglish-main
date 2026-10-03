<?php

namespace Tests\Feature;

use App\Http\Controllers\TeacherPortalController;
use App\Models\AcademicRecord;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Penalty;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Sla\TeacherSlaService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** SLA GV: nhận xét sau buổi học trong 12h, bậc phạt theo lần tái phạm (nhắc nhở → 30.000đ → 60.000đ). */
class TeacherSlaTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $teacher;

    private ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CSA', 'is_active' => true]);
        $this->admin = $this->user('admin');
        $this->teacher = $this->user('teacher');
        $course = Course::create(['code' => 'C1', 'name' => 'Starters', 'tuition_fee' => 5000000, 'is_active' => true]);
        $this->class = ClassModel::create(['code' => 'L1', 'name' => 'Lớp 1', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'teacher_id' => $this->teacher->id, 'max_capacity' => 10, 'status' => 'active']);
        SystemSetting::set('sla_teacher_live_since', now()->subDays(10)->toIso8601String());
    }

    public function test_late_remarks_follow_the_recidivism_ladder_and_cancelled_tickets_do_not_count(): void
    {
        $sessions = collect([4, 3, 2, 1])->map(fn (int $daysAgo) => $this->classSession($daysAgo));

        app(TeacherSlaService::class)->run();

        $penalties = Penalty::where('auto_source', 'gv.remarks_late')->orderBy('id')->get();
        $this->assertCount(4, $penalties);
        $this->assertSame([0.0, 30000.0, 60000.0, 60000.0], $penalties->map(fn ($p) => (float) $p->amount)->all());
        $this->assertSame('pending', $penalties[0]->status);
        $this->assertSame($this->teacher->id, $penalties[0]->user_id);
        $this->assertTrue(Storage::disk('local')->exists($penalties[1]->evidence_path));
        $this->assertStringContainsString('Lần thứ 2', $penalties[1]->notes);

        // Idempotent; biên bản đã hủy không tính vào bậc kế tiếp.
        app(TeacherSlaService::class)->run();
        $this->assertSame(4, Penalty::where('auto_source', 'gv.remarks_late')->count());
        $penalties[3]->update(['status' => 'cancelled']);
        $this->assertNotNull($sessions->last());
    }

    public function test_remarks_sent_within_12h_are_fine_and_late_ones_still_breach(): void
    {
        $onTime = $this->classSession(2);
        $late = $this->classSession(3);
        $this->remark($onTime, $this->endOf($onTime)->addHours(5));
        $this->remark($late, $this->endOf($late)->addHours(20));

        app(TeacherSlaService::class)->run();

        $this->assertSame(1, Penalty::where('auto_source', 'gv.remarks_late')->count());
        $this->assertStringContainsString('Lớp 1', Penalty::where('auto_source', 'gv.remarks_late')->first()->violation_type);
    }

    public function test_sessions_before_go_live_or_still_inside_the_window_are_ignored(): void
    {
        SystemSetting::set('sla_teacher_live_since', now()->subDay()->toIso8601String());
        $this->classSession(5);
        $recent = ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => now()->toDateString(), 'shift_name' => 'Ca', 'start_time' => now()->subHour()->format('H:i'), 'end_time' => now()->format('H:i'), 'teacher_id' => $this->teacher->id, 'status' => 'scheduled']);

        app(TeacherSlaService::class)->run();

        $this->assertSame(0, Penalty::count());
        $this->assertNotNull($recent->id);
    }

    public function test_admin_can_edit_the_ladder_and_reset_window(): void
    {
        $this->actingAs($this->admin)->put(route('system-config.sla.update', 'gv.remarks_late'), ['value' => 12, 'enabled' => 1, 'penalty' => 1, 'amount' => 0, 'ladder' => '0, 20000'])->assertRedirect();
        $this->actingAs($this->admin)->put(route('system-config.sla.update', 'gv.remarks_late'), ['value' => 12, 'enabled' => 1, 'penalty' => 1, 'ladder' => 'abc'])->assertSessionHasErrors('ladder');
        $this->actingAs($this->admin)->post(route('system-config.sla.settings'), ['ladder_reset_months' => 6])->assertRedirect();
        $this->classSession(3);
        $this->classSession(2);
        $this->classSession(1);

        app(TeacherSlaService::class)->run();

        $this->assertSame([0.0, 20000.0, 20000.0], Penalty::where('auto_source', 'gv.remarks_late')->orderBy('id')->get()->map(fn ($p) => (float) $p->amount)->all());
        $this->assertSame(6, \App\Services\Sla\Sla::ladderResetMonths());
    }

    private function classSession(int $daysAgo): ClassSession
    {
        return ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => now()->subDays($daysAgo)->toDateString(), 'shift_name' => 'Ca', 'start_time' => '08:00', 'end_time' => '09:00', 'teacher_id' => $this->teacher->id, 'status' => 'scheduled']);
    }

    private function endOf(ClassSession $session)
    {
        return $session->date->copy()->setTime(9, 0);
    }

    private function remark(ClassSession $session, $at): void
    {
        $record = AcademicRecord::create(['screen_key' => 'teacher_remarks', 'module' => 'teacher_remarks', 'record_code' => TeacherPortalController::remarkRecordCode($session), 'title' => 'Nhận xét', 'status' => 'completed', 'user_id' => $this->teacher->id, 'data' => []]);
        $record->forceFill(['updated_at' => $at])->saveQuietly();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
