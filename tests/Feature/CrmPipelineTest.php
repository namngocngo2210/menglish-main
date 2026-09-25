<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\CrmTrialBooking;
use App\Models\PlacementTest;
use App\Models\PlacementTestSubmission;
use App\Models\User;
use App\Services\CrmStageService;
use App\Services\PlacementPortalLinkService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pipeline CRM 8 bước + thất bại (BA chốt 2026-09-25):
 * CM (Học vụ / Quản lý cơ sở) chuyển tiến từng bước, chỉ Admin lùi bước (bắt buộc lý do),
 * không hủy chốt, lead thất bại không mở lại, Test / Đã test tự động.
 */
class CrmPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    private User $admin;

    private User $manager;

    private User $academic;

    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CSA', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'Cơ sở B', 'code' => 'CSB', 'is_active' => true]);
        $this->admin = $this->userWithRole('admin', $this->branch);
        $this->manager = $this->userWithRole('manager', $this->branch);
        $this->academic = $this->userWithRole('academic_staff', $this->branch);
        $this->sales = $this->userWithRole('sales_consultant', $this->branch);
    }

    public function test_pipeline_has_eight_stages_without_trial_or_closing_columns(): void
    {
        $response = $this->actingAs($this->admin)->get(route('crm.pipeline'))->assertOk();

        $this->assertSame(
            ['new', 'consulting', 'test_scheduled', 'testing', 'tested', 'result_sent', 'waiting_class', 'won'],
            collect($response->viewData('stages'))->pluck('id')->all()
        );
        $this->assertSame('Đang tư vấn', (new CrmCustomer(['stage' => 'consulting']))->stage_label);
        $this->assertSame('Thất bại', (new CrmCustomer(['stage' => 'lost']))->stage_label);
    }

    public function test_cm_moves_forward_exactly_one_step(): void
    {
        $lead = $this->lead('new');

        $this->actingAs($this->academic)->postJson(route('crm.customers.stage', $lead), ['stage' => 'consulting'])
            ->assertOk()->assertJsonFragment(['success' => true]);
        $this->assertSame('consulting', $lead->fresh()->stage);

        $this->actingAs($this->manager)->postJson(route('crm.customers.stage', $lead), ['stage' => 'tested'])
            ->assertUnprocessable();
        $this->assertSame('consulting', $lead->fresh()->stage);

        $this->actingAs($this->manager)->postJson(route('crm.customers.next-stage', $lead))
            ->assertOk();
        $this->assertSame('test_scheduled', $lead->fresh()->stage);

        $this->assertDatabaseHas('crm_customer_histories', [
            'customer_id' => $lead->id, 'type' => 'stage_change', 'from_stage' => 'consulting', 'to_stage' => 'test_scheduled',
        ]);
    }

    public function test_sales_cannot_change_stage_of_own_lead(): void
    {
        $lead = $this->lead('new');

        $this->actingAs($this->sales)->postJson(route('crm.customers.stage', $lead), ['stage' => 'consulting'])
            ->assertForbidden();
        $this->actingAs($this->sales)->postJson(route('crm.customers.next-stage', $lead))
            ->assertForbidden();
        $this->actingAs($this->sales)->postJson(route('crm.customers.stage', $lead), ['stage' => 'lost', 'lost_reason' => 'x'])
            ->assertForbidden();

        $this->assertSame('new', $lead->fresh()->stage);
    }

    public function test_closed_stages_can_only_be_reached_through_closing_flow(): void
    {
        $lead = $this->lead('result_sent');

        $this->actingAs($this->admin)->postJson(route('crm.customers.stage', $lead), ['stage' => 'waiting_class'])
            ->assertUnprocessable();
        $this->actingAs($this->admin)->postJson(route('crm.customers.stage', $lead), ['stage' => 'won'])
            ->assertUnprocessable();

        $this->assertSame('result_sent', $lead->fresh()->stage);
    }

    public function test_only_admin_moves_backward_with_reason_and_it_is_logged(): void
    {
        $lead = $this->lead('tested');

        $this->actingAs($this->manager)->postJson(route('crm.customers.stage', $lead), ['stage' => 'consulting', 'reason' => 'Nhập nhầm'])
            ->assertForbidden();
        $this->actingAs($this->admin)->postJson(route('crm.customers.stage', $lead), ['stage' => 'consulting'])
            ->assertUnprocessable();
        $this->assertSame('tested', $lead->fresh()->stage);

        $this->actingAs($this->admin)->postJson(route('crm.customers.stage', $lead), ['stage' => 'consulting', 'reason' => 'Nhập nhầm điểm test'])
            ->assertOk();

        $this->assertSame('consulting', $lead->fresh()->stage);
        $this->assertDatabaseHas('crm_customer_histories', [
            'customer_id' => $lead->id, 'type' => 'stage_change', 'user_id' => $this->admin->id,
            'from_stage' => 'tested', 'to_stage' => 'consulting', 'reason' => 'Nhập nhầm điểm test',
        ]);
    }

    public function test_closed_leads_never_go_backward_or_to_lost(): void
    {
        foreach (['won', 'waiting_class'] as $stage) {
            $lead = $this->lead($stage);

            $this->actingAs($this->admin)->postJson(route('crm.customers.stage', $lead), ['stage' => 'consulting', 'reason' => 'Hủy chốt'])
                ->assertUnprocessable();
            $this->actingAs($this->admin)->postJson(route('crm.customers.stage', $lead), ['stage' => 'lost', 'lost_reason' => 'Hủy'])
                ->assertUnprocessable();

            $this->assertSame($stage, $lead->fresh()->stage);
        }
    }

    public function test_lost_requires_reason_and_lost_leads_are_never_reopened(): void
    {
        $lead = $this->lead('consulting');

        $this->actingAs($this->academic)->postJson(route('crm.customers.stage', $lead), ['stage' => 'lost'])
            ->assertUnprocessable();
        $this->actingAs($this->academic)->postJson(route('crm.customers.stage', $lead), ['stage' => 'lost', 'lost_reason' => 'Không liên hệ được'])
            ->assertOk();
        $this->assertSame('lost', $lead->fresh()->stage);

        $this->actingAs($this->admin)->postJson(route('crm.customers.stage', $lead), ['stage' => 'consulting', 'reason' => 'Mở lại'])
            ->assertUnprocessable();
        $this->assertSame('lost', $lead->fresh()->stage);
    }

    public function test_opening_signed_test_link_moves_lead_to_testing(): void
    {
        $test = PlacementTest::create(['code' => 'PIPE-01', 'title' => 'Đề pipeline', 'is_active' => true, 'duration_minutes' => 30]);
        $lead = $this->lead('test_scheduled');
        $lead->update(['assigned_test_id' => $test->id]);
        $link = app(PlacementPortalLinkService::class)->signedLinkForLead($test, $lead);

        $this->get($link)->assertOk();

        $this->assertSame('testing', $lead->fresh()->stage);
        $this->assertDatabaseHas('crm_customer_histories', [
            'customer_id' => $lead->id, 'type' => 'stage_change', 'from_stage' => 'test_scheduled', 'to_stage' => 'testing',
        ]);

        // Mở lại link không tạo thêm lịch sử / không đổi giai đoạn.
        $this->get($link)->assertOk();
        $this->assertSame(1, $lead->histories()->where('to_stage', 'testing')->count());
    }

    public function test_portal_submission_keeps_lead_in_testing_until_graded(): void
    {
        $test = PlacementTest::create(['code' => 'PIPE-02', 'title' => 'Đề nộp bài', 'is_active' => true, 'duration_minutes' => 30]);
        $lead = $this->lead('test_scheduled');
        $link = app(PlacementPortalLinkService::class)->signedLinkForLead($test, $lead);
        $token = $this->get($link)->viewData('leadToken');

        $this->post(route('portal.test.submit', $test->code), [
            'candidate_name' => $lead->name,
            'candidate_phone' => $lead->phone,
            'lead_token' => $token,
        ])->assertRedirect();

        $this->assertSame('testing', $lead->fresh()->stage);
    }

    public function test_advance_hook_moves_forward_only_and_writes_history(): void
    {
        $service = app(CrmStageService::class);
        $lead = $this->lead('testing');

        $this->assertTrue($service->advanceTo($lead, 'tested', $this->academic, 'Học vụ chấm xong bài test'));
        $this->assertSame('tested', $lead->fresh()->stage);
        $this->assertDatabaseHas('crm_customer_histories', [
            'customer_id' => $lead->id, 'from_stage' => 'testing', 'to_stage' => 'tested', 'reason' => 'Học vụ chấm xong bài test',
        ]);

        // Không lùi, không đụng lead đã chốt / thất bại.
        $this->assertFalse($service->advanceTo($lead->fresh(), 'testing', null, 'Mở link'));
        $this->assertSame('tested', $lead->fresh()->stage);
        foreach (['won', 'waiting_class', 'lost'] as $stage) {
            $closed = $this->lead($stage);
            $this->assertFalse($service->advanceTo($closed, 'tested', null, 'Chấm bài'));
            $this->assertSame($stage, $closed->fresh()->stage);
        }
    }

    public function test_manager_and_academic_only_see_their_branch_leads(): void
    {
        $own = $this->lead('new');
        $foreign = $this->lead('new', $this->otherBranch);

        foreach ([$this->manager, $this->academic] as $user) {
            $this->actingAs($user)->get(route('crm.customers.show', $own))->assertOk();
            $this->actingAs($user)->get(route('crm.customers.show', $foreign))->assertNotFound();
            $this->actingAs($user)->postJson(route('crm.customers.stage', $foreign), ['stage' => 'consulting'])->assertNotFound();

            $ids = collect($this->actingAs($user)->get(route('crm.pipeline'))->viewData('stages'))
                ->flatMap(fn (array $stage) => collect($stage['leads'])->pluck('id'));
            $this->assertTrue($ids->contains($own->id));
            $this->assertFalse($ids->contains($foreign->id));
        }

        $this->actingAs($this->admin)->get(route('crm.customers.show', $foreign))->assertOk();
    }

    public function test_branch_scope_includes_additional_user_branches(): void
    {
        $foreign = $this->lead('new', $this->otherBranch);
        $this->manager->branches()->attach($this->otherBranch->id);

        $this->actingAs($this->manager)->get(route('crm.customers.show', $foreign))->assertOk();
    }

    public function test_sales_only_sees_assigned_leads(): void
    {
        $otherSales = $this->userWithRole('sales_consultant', $this->branch);
        $lead = $this->lead('new');
        $lead->update(['assigned_user_id' => $otherSales->id]);

        $this->actingAs($this->sales)->get(route('crm.customers.show', $lead))->assertNotFound();
        $this->actingAs($otherSales)->get(route('crm.customers.show', $lead))->assertOk();
    }

    public function test_kanban_escapes_lead_name_in_alpine_attributes_and_shows_parent(): void
    {
        $lead = $this->lead('new');
        $lead->update(['name' => "O'Brien</script><b>x", 'parent_name' => 'Phụ huynh Minh']);

        $response = $this->actingAs($this->academic)->get(route('crm.pipeline'))->assertOk();

        $response->assertDontSee("'O'Brien", false);
        $response->assertDontSee('</script><b>x', false);
        $response->assertSee('Phụ huynh Minh');
    }

    public function test_kanban_exposes_permissions_per_role(): void
    {
        $this->lead('new');

        $this->actingAs($this->sales)->get(route('crm.pipeline'))->assertOk()
            ->assertViewHas('stagePermissions', fn (array $p) => $p['canForward'] === false && $p['canBackward'] === false);
        $this->actingAs($this->academic)->get(route('crm.pipeline'))->assertOk()
            ->assertViewHas('stagePermissions', fn (array $p) => $p['canForward'] === true && $p['canBackward'] === false);
        $this->actingAs($this->admin)->get(route('crm.pipeline'))->assertOk()
            ->assertViewHas('stagePermissions', fn (array $p) => $p['canForward'] === true && $p['canBackward'] === true)
            ->assertSee('Lùi giai đoạn');
    }

    public function test_cm_books_trial_sessions_on_lead_without_changing_stage(): void
    {
        [$class, $sessions, $teacher] = $this->classWithSessions(3);
        $lead = $this->lead('consulting');

        $this->actingAs($this->academic)->post(route('crm.customers.trial-bookings.store', $lead), [
            'class_session_ids' => [$sessions[0]->id, $sessions[1]->id],
            'notes' => 'Bé thích học nhóm',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('consulting', $lead->fresh()->stage);
        $this->assertSame(2, CrmTrialBooking::where('customer_id', $lead->id)->count());
        $this->assertDatabaseHas('crm_customer_histories', ['customer_id' => $lead->id, 'type' => 'trial']);

        // Tối đa 2 buổi học thử cho mỗi khách.
        $this->actingAs($this->academic)->post(route('crm.customers.trial-bookings.store', $lead), [
            'class_session_ids' => [$sessions[2]->id],
        ])->assertSessionHasErrors('class_session_ids');
        $this->assertSame(2, CrmTrialBooking::where('customer_id', $lead->id)->count());

        $this->actingAs($this->academic)->get(route('crm.customers.show', $lead))->assertOk()
            ->assertSee('Học thử')->assertSee($class->name);
    }

    public function test_sales_cannot_book_trial_and_closed_lead_cannot_be_booked(): void
    {
        [, $sessions] = $this->classWithSessions(1);
        $lead = $this->lead('consulting');

        $this->actingAs($this->sales)->post(route('crm.customers.trial-bookings.store', $lead), [
            'class_session_ids' => [$sessions[0]->id],
        ])->assertForbidden();

        $won = $this->lead('won');
        $this->actingAs($this->academic)->post(route('crm.customers.trial-bookings.store', $won), [
            'class_session_ids' => [$sessions[0]->id],
        ])->assertSessionHasErrors('class_session_ids');

        $this->assertSame(0, CrmTrialBooking::count());
    }

    public function test_session_teacher_sees_trial_guest_and_writes_feedback_on_the_lead(): void
    {
        [, $sessions, $teacher] = $this->classWithSessions(1);
        $sessions[0]->update(['date' => today()->toDateString()]);
        $lead = $this->lead('consulting');
        $booking = CrmTrialBooking::create([
            'customer_id' => $lead->id,
            'class_id' => $sessions[0]->class_id,
            'class_session_id' => $sessions[0]->id,
            'booked_by' => $this->academic->id,
            'status' => 'scheduled',
        ]);

        $this->actingAs($teacher)->get(route('teacher.trial-guests'))->assertOk()->assertSee($lead->name);

        $this->actingAs($teacher)->post(route('teacher.trial-guests.feedback', $booking), [
            'status' => 'attended',
            'rating' => 4,
            'feedback' => 'Phát âm tốt, cần luyện nghe.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $booking->refresh();
        $this->assertSame('attended', $booking->status);
        $this->assertSame(4, $booking->rating);
        $this->assertSame($teacher->id, $booking->feedback_by);
        $this->assertSame('consulting', $lead->fresh()->stage);
        $this->assertDatabaseHas('crm_customer_histories', ['customer_id' => $lead->id, 'type' => 'trial', 'user_id' => $teacher->id]);

        $otherTeacher = $this->userWithRole('teacher', $this->branch);
        $this->actingAs($otherTeacher)->post(route('teacher.trial-guests.feedback', $booking), [
            'status' => 'attended', 'rating' => 1, 'feedback' => 'x',
        ])->assertForbidden();
        $this->actingAs($otherTeacher)->get(route('teacher.trial-guests'))->assertOk()->assertDontSee($lead->name);

        $this->actingAs($this->academic)->get(route('crm.customers.show', $lead))->assertOk()
            ->assertSee('Phát âm tốt, cần luyện nghe.');
    }

    public function test_academic_staff_and_manager_cannot_move_backward_even_with_reason(): void
    {
        $lead = $this->lead('result_sent');

        foreach ([$this->academic, $this->manager] as $user) {
            $this->actingAs($user)->postJson(route('crm.customers.stage', $lead), ['stage' => 'tested', 'reason' => 'Sửa nhầm'])
                ->assertForbidden();
        }
        $this->assertSame('result_sent', $lead->fresh()->stage);
        $this->assertSame(0, $lead->histories()->where('type', 'stage_change')->count());

        $this->actingAs($this->academic)->get(route('crm.customers.show', $lead))->assertOk()
            ->assertViewHas('stageControls', fn (array $c) => $c['backward'] === [] && $c['canLose'] === true);
        $this->actingAs($this->admin)->get(route('crm.customers.show', $lead))->assertOk()
            ->assertViewHas('stageControls', fn (array $c) => in_array('tested', $c['backward'], true));
    }

    public function test_only_not_yet_closed_leads_can_fail_from_any_active_stage(): void
    {
        foreach (['new', 'consulting', 'test_scheduled', 'testing', 'tested', 'result_sent'] as $stage) {
            $lead = $this->lead($stage);
            $this->actingAs($this->academic)->postJson(route('crm.customers.stage', $lead), ['stage' => 'lost', 'lost_reason' => 'Chưa phù hợp học phí'])
                ->assertOk();
            $this->assertSame('lost', $lead->fresh()->stage);
            $this->assertNotNull($lead->fresh()->lost_at);
        }

        foreach (['waiting_class', 'won'] as $stage) {
            $closed = $this->lead($stage);
            $this->actingAs($this->academic)->get(route('crm.customers.show', $closed))->assertOk()
                ->assertViewHas('stageControls', fn (array $c) => $c['canLose'] === false && $c['backward'] === [])
                ->assertDontSee('Hủy chốt');
        }
    }

    public function test_lost_lead_has_no_reopen_action_stays_listed_and_cannot_be_deleted(): void
    {
        $lead = $this->lead('consulting');
        $this->actingAs($this->manager)->postJson(route('crm.customers.stage', $lead), ['stage' => 'lost', 'lost_reason' => 'Không liên hệ được'])
            ->assertOk();

        // Không có thao tác mở lại trên hồ sơ; mọi đường chuyển giai đoạn đều bị server từ chối.
        $this->actingAs($this->admin)->get(route('crm.customers.show', $lead))->assertOk()
            ->assertViewHas('stageControls', fn (array $c) => $c['next'] === null && $c['backward'] === [] && $c['canLose'] === false)
            ->assertSee('không mở lại, giữ để đối soát');
        $this->actingAs($this->admin)->postJson(route('crm.customers.next-stage', $lead))->assertUnprocessable();
        foreach (['new', 'consulting', 'tested'] as $target) {
            $this->actingAs($this->admin)->postJson(route('crm.customers.stage', $lead), ['stage' => $target, 'reason' => 'Mở lại'])
                ->assertUnprocessable();
        }
        $this->assertFalse(app(CrmStageService::class)->advanceTo($lead->fresh(), 'consulting', $this->admin, 'Tự động'));

        // Không đặt học thử / không xóa (giữ để audit), vẫn nằm trong danh sách khách không chốt.
        [, $sessions] = $this->classWithSessions(1);
        $this->actingAs($this->admin)->post(route('crm.customers.trial-bookings.store', $lead), ['class_session_ids' => [$sessions[0]->id]])
            ->assertSessionHasErrors('class_session_ids');
        $this->actingAs($this->admin)->delete(route('crm.customers.destroy', $lead))->assertSessionHasErrors('customer');
        $this->assertNotSoftDeleted('crm_customers', ['id' => $lead->id]);
        $this->actingAs($this->manager)->get(route('crm.lost-deals'))->assertOk()->assertSee($lead->name);

        $this->assertSame('lost', $lead->fresh()->stage);
        $this->assertSame(1, $lead->histories()->where('type', 'stage_change')->count());
    }

    public function test_trial_can_be_booked_for_any_not_closed_consulting_stage_and_cancel_frees_a_slot(): void
    {
        [, $sessions] = $this->classWithSessions(3);
        $lead = $this->lead('tested');

        $this->actingAs($this->academic)->post(route('crm.customers.trial-bookings.store', $lead), [
            'class_session_ids' => [$sessions[0]->id, $sessions[1]->id],
        ])->assertSessionHasNoErrors();
        // Một lần gửi quá 2 buổi cũng bị chặn.
        $other = $this->lead('consulting');
        $this->actingAs($this->academic)->post(route('crm.customers.trial-bookings.store', $other), [
            'class_session_ids' => [$sessions[0]->id, $sessions[1]->id, $sessions[2]->id],
        ])->assertSessionHasErrors('class_session_ids');

        $booking = CrmTrialBooking::where('customer_id', $lead->id)->firstOrFail();
        $this->actingAs($this->academic)->post(route('crm.customers.trial-bookings.cancel', [$lead->id, $booking->id]), ['reason' => 'Khách bận'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->academic)->post(route('crm.customers.trial-bookings.store', $lead), [
            'class_session_ids' => [$sessions[2]->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, CrmTrialBooking::where('customer_id', $lead->id)->where('status', '!=', 'cancelled')->count());
        $this->assertSame('tested', $lead->fresh()->stage);
    }

    public function test_trial_sessions_of_matching_level_are_flagged_first(): void
    {
        $teacher = $this->userWithRole('teacher', $this->branch);
        $movers = Course::create(['code' => 'MOV', 'name' => 'Movers FAM 2', 'tuition_fee' => 1, 'is_active' => true]);
        $starters = Course::create(['code' => 'STA', 'name' => 'Starters FAM 1', 'tuition_fee' => 1, 'is_active' => true]);
        $sessionFor = function (Course $course, string $code, int $days) use ($teacher) {
            $class = ClassModel::create(['code' => $code, 'name' => 'Lớp '.$code, 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'teacher_id' => $teacher->id, 'max_capacity' => 10, 'status' => 'active']);

            return ClassSession::create(['class_id' => $class->id, 'branch_id' => $this->branch->id, 'date' => now()->addDays($days)->toDateString(), 'shift_name' => 'Ca', 'start_time' => '18:00', 'end_time' => '19:30', 'teacher_id' => $teacher->id, 'status' => 'scheduled']);
        };
        $moversSession = $sessionFor($movers, 'MV1', 1);
        $startersSession = $sessionFor($starters, 'ST1', 2);

        $lead = $this->lead('tested');
        $test = PlacementTest::create(['code' => 'TEST-G2-G3', 'title' => 'Đề', 'is_active' => true, 'duration_minutes' => 30]);
        $submission = new PlacementTestSubmission(['placement_test_id' => $test->id, 'customer_id' => $lead->id, 'candidate_name' => $lead->name, 'candidate_phone' => $lead->phone, 'status' => 'graded']);
        $submission->applyRubricGrade(['grade_group' => 'khoi_2_3', 'listening_score' => 12, 'reading_writing_score' => 12, 'speaking_score' => 8]);
        $submission->save();

        $sessions = $this->actingAs($this->academic)->get(route('crm.customers.show', $lead))->assertOk()
            ->assertSee('Khớp trình độ')->viewData('trialSessions');
        $this->assertSame([$startersSession->id, $moversSession->id], $sessions->pluck('id')->all());
        $this->assertTrue($sessions->first()->matches_level);
        $this->assertFalse($sessions->last()->matches_level);
    }

    public function test_teacher_records_structured_remark_for_trial_guest_after_the_session_only(): void
    {
        [, $sessions, $teacher] = $this->classWithSessions(1);
        $lead = $this->lead('consulting');
        $booking = CrmTrialBooking::create([
            'customer_id' => $lead->id, 'class_id' => $sessions[0]->class_id, 'class_session_id' => $sessions[0]->id,
            'booked_by' => $this->academic->id, 'status' => 'scheduled',
        ]);
        $payload = [
            'status' => 'attended', 'rating' => 5,
            'remarks' => ['grammar' => 'Khá', 'attitude' => 'Hăng hái', 'result' => 'Đạt mục tiêu', 'monsters' => 'bỏ qua'],
            'feedback' => 'Bé hòa nhập nhanh, hợp lớp Starters.',
        ];

        // Buổi ngày mai: GV thấy khách ở mục "sắp tới" nhưng chưa được nhận xét.
        $this->actingAs($teacher)->get(route('teacher.trial-guests'))->assertOk()->assertSee($lead->name)->assertSee('Nhận xét được mở từ ngày học thử');
        $this->actingAs($teacher)->post(route('teacher.trial-guests.feedback', $booking), $payload)->assertSessionHasErrors('feedback');
        $this->assertNull($booking->fresh()->feedback_at);

        $sessions[0]->update(['date' => now()->subDay()->toDateString()]);
        $this->actingAs($teacher)->get(route('teacher.trial-guests', ['scope' => 'past']))->assertOk()->assertSee($lead->name);
        $this->actingAs($teacher)->get(route('teacher.trial-guests'))->assertOk()->assertDontSee($lead->name);

        $this->actingAs($teacher)->post(route('teacher.trial-guests.feedback', $booking), $payload)->assertSessionHasNoErrors();

        $booking->refresh();
        $this->assertSame(['grammar' => 'Khá', 'attitude' => 'Hăng hái', 'result' => 'Đạt mục tiêu'], $booking->remarks);
        $this->assertSame($lead->id, $booking->customer_id);
        $history = $lead->histories()->where('type', 'trial')->latest('id')->firstOrFail();
        $this->assertSame($teacher->id, $history->user_id);
        $this->assertStringContainsString('Thực hành ngữ pháp: Khá', $history->content);
        $this->assertStringContainsString('Bé hòa nhập nhanh', $history->content);

        $this->actingAs($this->academic)->get(route('crm.customers.show', $lead))->assertOk()
            ->assertSee('Tinh thần học tập: Hăng hái')->assertSee('Bé hòa nhập nhanh, hợp lớp Starters.');
        $this->assertDatabaseMissing('students', ['name' => $lead->name]);
    }

    private function classWithSessions(int $count): array
    {
        $teacher = $this->userWithRole('teacher', $this->branch);
        $course = Course::create(['code' => 'PIPE-C', 'name' => 'Starters', 'tuition_fee' => 5000000, 'is_active' => true]);
        $class = ClassModel::create([
            'code' => 'PIPE-L1', 'name' => 'Lớp Starters 1', 'course_id' => $course->id, 'branch_id' => $this->branch->id,
            'teacher_id' => $teacher->id, 'max_capacity' => 10, 'status' => 'active',
        ]);
        $sessions = collect(range(1, $count))->map(fn (int $i) => ClassSession::create([
            'class_id' => $class->id, 'branch_id' => $this->branch->id,
            'date' => now()->addDays($i)->toDateString(), 'shift_name' => 'Ca tối',
            'start_time' => '18:00', 'end_time' => '19:30', 'teacher_id' => $teacher->id, 'status' => 'scheduled',
        ]))->all();

        return [$class, $sessions, $teacher];
    }

    private function lead(string $stage, ?Branch $branch = null): CrmCustomer
    {
        return CrmCustomer::create([
            'code' => CrmCustomer::generateCode(),
            'name' => 'Lead '.$stage,
            'phone' => '09'.random_int(10000000, 99999999),
            'branch_id' => ($branch ?? $this->branch)->id,
            'assigned_user_id' => $this->sales->id,
            'stage' => $stage,
        ]);
    }

    private function userWithRole(string $role, Branch $branch): User
    {
        $user = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
