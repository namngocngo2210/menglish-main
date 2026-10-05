<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\CrmCustomer;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CrmCustomerHistory;
use App\Models\CrmTrialBooking;
use App\Models\Penalty;
use App\Models\PlacementTest;
use App\Models\PlacementTestSubmission;
use App\Models\SlaEvent;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Sla\CrmSlaService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** SLA CRM tự động: đếm ngược từ lúc thêm khách → quá hạn tự lập biên bản (kèm file chi tiết) + báo Admin / người phụ trách. */
class CrmSlaTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CSA', 'is_active' => true]);
        $this->admin = $this->user('admin');
        $this->owner = $this->user('academic_staff');
        // SLA đã bật từ 10 ngày trước → khách tạo trong khoảng đó đều bị tính.
        SystemSetting::set('sla_crm_live_since', now()->subDays(10)->toIso8601String());
    }

    public function test_new_lead_without_contact_after_24h_gets_auto_penalty_with_detail_file_and_notifications(): void
    {
        $lead = $this->lead(hoursAgo: 25);

        $stats = app(CrmSlaService::class)->run();

        $this->assertSame(1, $stats['crm.first_contact']);
        $penalty = Penalty::where('auto_source', 'crm.first_contact')->firstOrFail();
        $this->assertSame($this->owner->id, $penalty->user_id);
        $this->assertSame('pending', $penalty->status);
        $this->assertNull($penalty->reporter_id);
        $this->assertSame('Tự động', $penalty->source_label);
        $this->assertTrue(Storage::disk('local')->exists($penalty->evidence_path), 'file chi tiết đính kèm');
        $this->assertSame($lead->created_at->copy()->addHours(24)->toDateTimeString(), $penalty->violation_at->toDateTimeString());
        foreach ([$this->owner, $this->admin] as $recipient) {
            $this->assertTrue(AdminNotification::where('user_id', $recipient->id)->where('type', 'penalty_created')->exists());
        }

        // Idempotent.
        app(CrmSlaService::class)->run();
        $this->assertSame(1, Penalty::where('auto_source', 'crm.first_contact')->count());
    }

    public function test_contact_within_24h_or_change_of_stage_avoids_penalty(): void
    {
        $contacted = $this->lead(hoursAgo: 30);
        $this->history(['customer_id' => $contacted->id, 'user_id' => $this->owner->id, 'type' => 'call', 'outcome' => 'reached', 'content' => 'Gọi'], 20);
        $moved = $this->lead(hoursAgo: 30, attributes: ['stage' => 'consulting']);
        $this->history(['customer_id' => $moved->id, 'user_id' => $this->owner->id, 'type' => 'stage_change', 'content' => 'Chuyển'], 10);

        app(CrmSlaService::class)->run();

        $this->assertSame(0, Penalty::where('auto_source', 'crm.first_contact')->count());
        $this->assertSame(0, Penalty::where('auto_source', 'crm.status_move')->where('violation_type', 'like', '%'.$moved->code.'%')->count());
    }

    public function test_failed_call_does_not_count_as_contact_and_status_move_has_its_own_36h_limit(): void
    {
        $lead = $this->lead(hoursAgo: 40);
        $this->history(['customer_id' => $lead->id, 'user_id' => $this->owner->id, 'type' => 'call', 'outcome' => 'failed', 'content' => 'Không bắt máy'], 30);
        $reached = $this->lead(hoursAgo: 40);
        $this->history(['customer_id' => $reached->id, 'user_id' => $this->owner->id, 'type' => 'call', 'outcome' => 'reached', 'content' => 'Đã nghe máy'], 30);

        app(CrmSlaService::class)->run();

        $this->assertTrue(Penalty::where('auto_source', 'crm.first_contact')->where('violation_type', 'like', '%'.$lead->code.'%')->exists());
        $this->assertFalse(Penalty::where('auto_source', 'crm.first_contact')->where('violation_type', 'like', '%'.$reached->code.'%')->exists());
        // Cả hai vẫn "Mới" quá 36h → quá hạn chuyển trạng thái.
        $this->assertSame(2, Penalty::where('auto_source', 'crm.status_move')->count());
    }

    public function test_leads_created_before_sla_went_live_are_not_penalized(): void
    {
        $this->lead(hoursAgo: 24 * 12);

        app(CrmSlaService::class)->run();

        $this->assertSame(0, Penalty::count());
    }

    public function test_admin_can_change_threshold_and_disable_penalty(): void
    {
        $this->actingAs($this->admin)->get(route('system-config.sla'))->assertOk()->assertSee('Liên hệ khách mới lần đầu');
        $this->actingAs($this->owner)->get(route('system-config.sla'))->assertForbidden();

        $this->actingAs($this->admin)->put(route('system-config.sla.update', 'crm.first_contact'), ['value' => 48, 'enabled' => 1, 'penalty' => 0, 'amount' => 0])
            ->assertRedirect();
        $lead = $this->lead(hoursAgo: 50);

        app(CrmSlaService::class)->run();

        $this->assertSame(0, Penalty::where('auto_source', 'crm.first_contact')->count(), 'tắt tự phạt → chỉ thông báo');
        $this->assertTrue(AdminNotification::where('user_id', $this->admin->id)->where('type', 'sla_breach')->where('title', 'like', '%'.$lead->code.'%')->exists());

        $this->actingAs($this->admin)->put(route('system-config.sla.update', 'crm.first_contact'), ['value' => 0, 'enabled' => 1, 'penalty' => 1])
            ->assertSessionHasErrors('value');
    }

    public function test_test_submission_creates_task_immediately_and_penalty_when_result_not_sent(): void
    {
        $lead = $this->lead(hoursAgo: 1, attributes: ['stage' => 'tested']);
        $test = PlacementTest::create(['code' => 'T1', 'title' => 'Đề', 'is_active' => true]);
        $submission = PlacementTestSubmission::create([
            'placement_test_id' => $test->id, 'customer_id' => $lead->id, 'candidate_name' => $lead->name, 'candidate_phone' => $lead->phone, 'status' => 'pending',
        ]);

        app(CrmSlaService::class)->run();
        $event = SlaEvent::where('rule_key', 'crm.test_result')->firstOrFail();
        $this->assertNotNull($event->work_task_id);
        $this->assertSame($this->owner->id, WorkTask::find($event->work_task_id)->assignee_id);
        $this->assertSame(0, Penalty::where('auto_source', 'crm.test_result')->count());

        $this->travel(25)->hours();
        app(CrmSlaService::class)->run();
        $this->assertSame(1, Penalty::where('auto_source', 'crm.test_result')->count());
        $this->assertSame('overdue', WorkTask::find($event->work_task_id)->status);
        $this->assertNotNull($submission->fresh());
    }

    public function test_test_result_sent_in_time_closes_the_task(): void
    {
        $lead = $this->lead(hoursAgo: 1, attributes: ['stage' => 'tested']);
        $test = PlacementTest::create(['code' => 'T1', 'title' => 'Đề', 'is_active' => true]);
        PlacementTestSubmission::create(['placement_test_id' => $test->id, 'customer_id' => $lead->id, 'candidate_name' => $lead->name, 'candidate_phone' => $lead->phone, 'status' => 'pending']);
        app(CrmSlaService::class)->run();
        CrmCustomerHistory::create(['customer_id' => $lead->id, 'user_id' => $this->owner->id, 'type' => 'result', 'content' => 'Đã gửi KQ']);

        app(CrmSlaService::class)->run();

        $event = SlaEvent::where('rule_key', 'crm.test_result')->firstOrFail();
        $this->assertNotNull($event->resolved_at);
        $this->assertSame('completed', WorkTask::find($event->work_task_id)->status);
        $this->travel(30)->hours();
        app(CrmSlaService::class)->run();
        $this->assertSame(0, Penalty::where('auto_source', 'crm.test_result')->count());
    }

    public function test_three_failed_contacts_warn_admin_and_assign_report_task_once(): void
    {
        $lead = $this->lead(hoursAgo: 1);
        $post = fn (string $outcome, string $type = 'call') => $this->actingAs($this->owner)
            ->post(route('crm.customers.notes.store', $lead->id), ['type' => $type, 'content' => 'Liên hệ', 'outcome' => $outcome]);

        $post('failed', 'call');
        $post('failed', 'message');
        $this->assertSame(0, AdminNotification::where('type', 'sla_breach')->count());
        $post('failed', 'meet');

        $this->assertSame(1, SlaEvent::where('rule_key', 'crm.failed_contacts')->count());
        $this->assertTrue(AdminNotification::where('user_id', $this->admin->id)->where('type', 'sla_breach')->exists());
        $this->assertTrue(WorkTask::where('assignee_id', $this->owner->id)->where('title', 'like', 'Báo cáo liên hệ thất bại%')->exists());

        $post('failed');
        $this->assertSame(1, SlaEvent::where('rule_key', 'crm.failed_contacts')->count(), 'cùng một chuỗi chỉ cảnh báo một lần');

        $post('reached');
        $post('failed');
        $post('failed');
        $post('failed');
        $this->assertSame(2, SlaEvent::where('rule_key', 'crm.failed_contacts')->count(), 'chuỗi mới sau lần liên hệ được');
    }

    public function test_tuition_follow_up_task_is_created_on_closing_and_reported_when_unpaid_after_a_week(): void
    {
        $lead = $this->lead(hoursAgo: 2, attributes: ['stage' => 'waiting_class', 'converted_at' => now()->subHour()]);

        app(CrmSlaService::class)->run();
        $event = SlaEvent::where('rule_key', 'crm.tuition_followup')->firstOrFail();
        $this->assertNotNull($event->work_task_id);

        $this->travel(8)->days();
        app(CrmSlaService::class)->run();
        $this->assertNotNull($event->fresh()->breached_at);
        $this->assertSame(0, Penalty::where('auto_source', 'crm.tuition_followup')->count(), 'mặc định không tự phạt');
        $this->assertTrue(AdminNotification::where('user_id', $this->admin->id)->where('type', 'sla_breach')->where('title', 'like', '%'.$lead->code.'%')->exists());
    }

    public function test_trial_feedback_task_starts_at_session_end_and_breaches_after_24h_without_contact(): void
    {
        // Cố định giờ trong ngày: chạy sau 22:30 thì "+2 giờ" đã quá hạn 24h tính từ 00:30 (test lệ thuộc giờ chạy).
        $this->travelTo(now()->setTime(9, 0));
        $teacher = $this->user('teacher');
        $course = Course::create(['code' => 'C1', 'name' => 'Starters', 'tuition_fee' => 5000000, 'is_active' => true]);
        $class = ClassModel::create(['code' => 'L1', 'name' => 'Lớp 1', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'teacher_id' => $teacher->id, 'max_capacity' => 10, 'status' => 'active']);
        $session = ClassSession::create(['class_id' => $class->id, 'branch_id' => $this->branch->id, 'date' => now()->toDateString(), 'shift_name' => 'Ca', 'start_time' => '00:00', 'end_time' => '00:30', 'teacher_id' => $teacher->id, 'status' => 'scheduled']);
        $lead = $this->lead(hoursAgo: 5, attributes: ['stage' => 'consulting']);
        CrmTrialBooking::create(['customer_id' => $lead->id, 'class_id' => $class->id, 'class_session_id' => $session->id, 'booked_by' => $this->owner->id, 'status' => 'scheduled']);
        $this->travel(2)->hours(); // buổi học thử (00:30) đã kết thúc

        app(CrmSlaService::class)->run();
        $event = SlaEvent::where('rule_key', 'crm.trial_feedback')->firstOrFail();
        $this->assertNotNull($event->work_task_id);
        $this->assertSame(0, Penalty::where('auto_source', 'crm.trial_feedback')->count());

        $this->travel(25)->hours();
        app(CrmSlaService::class)->run();
        $this->assertSame(1, Penalty::where('auto_source', 'crm.trial_feedback')->count());
    }

    private function lead(int $hoursAgo, array $attributes = []): CrmCustomer
    {
        $phone = '09'.random_int(10000000, 99999999);
        $lead = CrmCustomer::create(array_merge([
            'code' => CrmCustomer::generateCode(),
            'name' => 'Khách '.random_int(100, 999),
            'phone' => $phone,
            'phone_normalized' => $phone,
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->owner->id,
            'source' => 'Facebook',
            'stage' => 'new',
        ], $attributes));
        $lead->forceFill(['created_at' => now()->subHours($hoursAgo)])->saveQuietly();

        return $lead->fresh();
    }

    /** Nhật ký với mốc thời gian chỉ định (created_at không mass-assign được). */
    private function history(array $attributes, int $hoursAgo): CrmCustomerHistory
    {
        $history = CrmCustomerHistory::create($attributes);
        $history->forceFill(['created_at' => now()->subHours($hoursAgo)])->saveQuietly();

        return $history;
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
