<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\SyllabusCurriculum;
use App\Models\User;
use App\Services\SyllabusProgressionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Xin giãn tiến độ giáo trình: duyệt trong 3 ngày (SLA), báo người duyệt (chỉ Admin, 06/10/2026) khi gửi và 1 lần khi quá hạn. */
class AdjustmentSlaTest extends TestCase
{
    use RefreshDatabase;

    private User $academic;

    private User $admin;

    private User $teacher;

    private ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $branch = Branch::create(['name' => 'Cơ sở GTD', 'code' => 'GTD', 'is_active' => true]);
        $this->academic = User::factory()->create(['is_active' => true]);
        $this->academic->assignRole('academic_lead');
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->teacher = User::factory()->create(['is_active' => true]);
        $this->teacher->assignRole('teacher');
        $this->class = ClassModel::create(['code' => 'GTD-A', 'name' => 'Lớp GTD A', 'branch_id' => $branch->id, 'teacher_id' => $this->teacher->id, 'status' => 'active']);
        $curriculum = SyllabusCurriculum::create(['code' => 'CUR-GTD', 'title' => 'GT GTD', 'version' => 'v1', 'stage_name' => 'Chặng GTD']);
        app(SyllabusProgressionService::class)->open($this->class, $curriculum->stages()->firstOrFail(), $this->teacher->id, $this->academic);
    }

    private function submit(): SyllabusAdjustmentRequest
    {
        $this->actingAs($this->teacher)->post(route('syllabus.adjustment-requests.store'), [
            'class_id' => $this->class->id, 'reason' => 'Lớp học chậm', 'extra_sessions' => 2,
        ])->assertSessionHasNoErrors();

        return SyllabusAdjustmentRequest::latest('id')->firstOrFail();
    }

    public function test_sla_is_three_days(): void
    {
        $this->assertSame(3, SyllabusAdjustmentRequest::slaDays());
        $this->assertSame(72, SyllabusAdjustmentRequest::slaHours());

        $req = $this->submit();
        $this->assertTrue($req->sla_due_at->equalTo($req->created_at->copy()->addDays(3)));
        $this->assertFalse($req->isSlaOverdue());

        $this->travel(71)->hours();
        $this->assertFalse($req->fresh()->isSlaOverdue());
        $this->travel(2)->hours();
        $this->assertTrue($req->fresh()->isSlaOverdue());
    }

    public function test_teacher_screen_states_three_days(): void
    {
        $this->actingAs($this->teacher)->get(route('syllabus.teacher-adjust'))->assertOk()
            ->assertSee('trong vòng 3 ngày')->assertDontSee('trong vòng 24 giờ');
    }

    public function test_approvers_are_notified_on_creation(): void
    {
        $req = $this->submit();

        // Chỉ Admin duyệt (06/10/2026) → chỉ Admin nhận thông báo chờ duyệt; Học thuật không còn nhận.
        $notice = AdminNotification::where('user_id', $this->admin->id)->where('type', 'adjustment_pending')->sole();
        $this->assertSame($req->id, $notice->data['request_id']);
        foreach ([$this->academic, $this->teacher] as $other) {
            $this->assertSame(0, AdminNotification::where('user_id', $other->id)->where('type', 'adjustment_pending')->count());
        }
    }

    public function test_breach_notification_is_sent_once_after_three_days(): void
    {
        $req = $this->submit();

        $this->travel(2)->days();
        $this->artisan('syllabus:notify-adjustment-sla')->assertSuccessful();
        $this->assertSame(0, AdminNotification::where('type', 'adjustment_sla')->count());

        $this->travel(2)->days();
        $this->artisan('syllabus:notify-adjustment-sla')->assertSuccessful();
        $this->artisan('syllabus:notify-adjustment-sla')->assertSuccessful();
        // Quá hạn → báo đúng 1 lần cho Admin (người duyệt duy nhất); Học thuật không nhận.
        $this->assertSame(1, AdminNotification::where('user_id', $this->admin->id)->where('type', 'adjustment_sla')->count());
        $this->assertSame(0, AdminNotification::where('user_id', $this->academic->id)->where('type', 'adjustment_sla')->count());
        $this->assertNotNull($req->fresh()->sla_notified_at);
    }

    public function test_handled_request_does_not_trigger_breach_notice(): void
    {
        $req = $this->submit();
        $req->update(['status' => 'rejected']);

        $this->travel(5)->days();
        $this->artisan('syllabus:notify-adjustment-sla')->assertSuccessful();

        $this->assertSame(0, AdminNotification::where('type', 'adjustment_sla')->count());
    }

    public function test_admin_can_approve_adjustment_request(): void
    {
        $req = $this->submit();

        // Học thuật không còn duyệt được (chỉ Admin, 06/10/2026).
        $this->actingAs($this->academic)->post(route('syllabus.adjustment-requests.approve', $req->id), ['extra_sessions' => 0])
            ->assertForbidden();
        $this->assertSame('pending', $req->fresh()->status);

        $this->actingAs($this->admin)->post(route('syllabus.adjustment-requests.approve', $req->id), ['extra_sessions' => 0])
            ->assertSessionHasNoErrors();

        $req->refresh();
        $this->assertSame('approved', $req->status);
        $this->assertSame($this->admin->id, $req->approver_id);
        $this->assertTrue(AdminNotification::where('user_id', $this->teacher->id)->where('title', 'Yêu cầu giãn tiến độ đã được duyệt')->exists());
    }
}
