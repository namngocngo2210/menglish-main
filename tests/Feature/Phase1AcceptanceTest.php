<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\CrmTrialBooking;
use App\Models\Holiday;
use App\Models\PlacementTest;
use App\Models\PlacementTestSubmission;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\SessionScheduleService;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nghiệm thu Phase 1 (docs/audit-report-and-roadmap.md — Phần C, "Kết quả đạt được"):
 * chạy trọn luồng nhập khách → test online → học thử → chốt → học viên vào lớp hoặc vào lớp chờ,
 * qua HTTP bằng đúng vai trò thật, theo các quyết định A6 (pipeline 8 bước, CM tiến từng bước,
 * chỉ Admin lùi bước kèm lý do, không hủy chốt, Thất bại không mở lại, học thử ≤ 2 buổi gắn với khách,
 * chấm test theo khối lớp, chốt có lớp / xếp lớp sau, chưa đóng phí → task nhắc thu, phạm vi chi nhánh).
 */
class Phase1AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    private User $admin;

    private User $manager;

    private User $academic;

    private User $sales;

    private User $teacher;

    private User $otherTeacher;

    private User $otherManager;

    private Course $course;

    private ClassModel $activeClass;

    private ClassModel $upcomingClass;

    private PlacementTest $test;

    private Holiday $holiday;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở Nghiệm thu', 'code' => 'NT', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'Cơ sở Khác', 'code' => 'NK', 'is_active' => true]);

        $this->admin = $this->userWithRole('admin', $this->branch);
        $this->manager = $this->userWithRole('manager', $this->branch);
        $this->academic = $this->userWithRole('academic_staff', $this->branch);
        $this->sales = $this->userWithRole('sales_consultant', $this->branch);
        $this->teacher = $this->userWithRole('teacher', $this->branch);
        $this->otherTeacher = $this->userWithRole('teacher', $this->branch);
        $this->otherManager = $this->userWithRole('manager', $this->otherBranch);

        $this->course = Course::create(['code' => 'FAM1-NT', 'name' => 'Starters FAM 1', 'tuition_fee' => 9000000, 'total_lessons' => 36, 'is_active' => true]);

        // Ngày nghỉ của chi nhánh trong lịch lớp: buổi rơi vào ngày này không được sinh.
        $this->holiday = Holiday::create([
            'code' => 'NL-NT', 'name' => 'Nghỉ cơ sở', 'start_date' => today()->addDays(7), 'end_date' => today()->addDays(7), 'is_system_wide' => false,
        ]);
        $this->holiday->branches()->sync([$this->branch->id]);

        // Lớp đang học (đã khai giảng tuần trước), học mọi ngày trong tuần để luôn có buổi hôm nay cho học thử.
        $this->activeClass = $this->makeClassWithSessions('NT-FAM1-A', 'active', today()->subWeek(), today()->addWeeks(3), ['max_capacity' => 10]);
        // Lớp sắp khai giảng, 2 chỗ.
        $this->upcomingClass = $this->makeClassWithSessions('NT-FAM1-B', 'upcoming', today()->addDays(10), today()->addWeeks(6), ['max_capacity' => 2]);

        $this->test = PlacementTest::create([
            'code' => 'TEST-G1-G2-NT',
            'title' => 'Đề test lớp 1 lên lớp 2',
            'target_level' => 'Starters',
            'duration_minutes' => 30,
            'questions_count' => 4,
            'is_active' => true,
            'questions' => [
                ['id' => 1, 'skill' => 'listening', 'type' => 'multiple_choice', 'title' => 'L1', 'correct_answer' => 'A', 'points' => 1],
                ['id' => 2, 'skill' => 'listening', 'type' => 'multiple_choice', 'title' => 'L2', 'correct_answer' => 'B', 'points' => 1],
                ['id' => 3, 'skill' => 'reading', 'type' => 'multiple_choice', 'title' => 'R1', 'correct_answer' => 'C', 'points' => 1],
                ['id' => 4, 'skill' => 'writing', 'type' => 'essay', 'title' => 'W1', 'points' => 1],
            ],
        ]);
    }

    /**
     * Luồng chính: Sale nhập khách → CM tiến từng bước → khách làm test online qua link riêng → Học vụ chấm theo thang khối lớp
     * → CM đặt học thử (≤ 2 buổi) → GV nhận xét (lưu theo khách) → chốt (a) có lớp còn chỗ / (b) xếp lớp sau → Học vụ gán lớp
     * → chưa đóng phí thì có task nhắc thu → Xác nhận chính thức.
     */
    public function test_full_journey_from_new_lead_to_student_in_class_or_waiting_list(): void
    {
        // Holiday respected when sessions were generated.
        $this->assertFalse(ClassSession::where('class_id', $this->activeClass->id)->whereDate('date', $this->holiday->start_date)->exists());
        $this->assertTrue(ClassSession::where('class_id', $this->activeClass->id)->whereDate('date', today())->exists());

        // ── 1. Sale nhập khách (kiểm tra SĐT) ────────────────────────────
        $this->actingAs($this->sales)->post(route('crm.customers.store'), $this->customerPayload(['phone' => '0123 456']))
            ->assertSessionHasErrors('phone');
        $this->actingAs($this->sales)->post(route('crm.customers.store'), $this->customerPayload(['parent_phone' => '12345']))
            ->assertSessionHasErrors('parent_phone');
        $this->assertSame(0, CrmCustomer::count());

        $this->actingAs($this->sales)->post(route('crm.customers.store'), $this->customerPayload())->assertRedirect();
        $lead = CrmCustomer::where('phone_normalized', '0912345601')->firstOrFail();
        $this->assertSame('new', $lead->stage);
        $this->assertSame($this->sales->id, $lead->assigned_user_id);
        // Trùng SĐT (khác cách viết) bị chặn.
        $this->actingAs($this->sales)->post(route('crm.customers.store'), $this->customerPayload(['phone' => '+84 912 345 601', 'name' => 'Trùng']))
            ->assertSessionHasErrors('phone');

        // Sale không đổi giai đoạn; vẫn ghi nhật ký chăm sóc.
        $this->actingAs($this->sales)->post(route('crm.customers.next-stage', $lead->id))->assertForbidden();
        $this->actingAs($this->sales)->post(route('crm.customers.notes.store', $lead->id), ['type' => 'call', 'content' => 'Gọi tư vấn lần 1, PH quan tâm lớp Starters.'])
            ->assertRedirect();
        $this->assertSame('new', $lead->fresh()->stage);

        // ── 2. CM tiến từng bước, không nhảy cóc ──────────────────────────
        $this->actingAs($this->academic)->from(route('crm.customers.show', $lead->id))
            ->post(route('crm.customers.stage', $lead->id), ['stage' => 'test_scheduled'])
            ->assertSessionHasErrors('stage');
        $this->actingAs($this->academic)->post(route('crm.customers.next-stage', $lead->id))->assertSessionHasNoErrors();
        $this->assertSame('consulting', $lead->fresh()->stage);

        // Hẹn test: gán đề + người chấm → "Hẹn test" tự động.
        $this->actingAs($this->academic)->post(route('crm.customers.schedule-test', $lead->id), [
            'appointment_date' => today()->addDay()->toDateString(),
            'appointment_time' => '09:00',
            'appointment_type' => 'online',
            'assigned_test_id' => $this->test->id,
            'examiner_id' => $this->academic->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame('test_scheduled', $lead->fresh()->stage);

        // ── 3. Khách mở link test riêng (có chữ ký) → "Test"; nộp bài → chờ chấm ──
        $link = $this->actingAs($this->academic)->get(route('crm.customers.show', $lead->id))->assertOk()->viewData('portalTestLink');
        $this->assertNotNull($link);
        auth()->logout();
        // Link bị sửa (đổi lead) không còn chữ ký hợp lệ → không điền sẵn thông tin khách.
        $this->assertNull($this->get(str_replace('lead='.$lead->id, 'lead=999', $link))->assertOk()->viewData('lead'));
        $take = $this->get($link)->assertOk();
        $this->assertSame($lead->id, $take->viewData('lead')->id);
        $token = $take->viewData('leadToken');
        $this->assertSame('testing', $lead->fresh()->stage);

        $this->post(route('portal.test.submit', $this->test->code), [
            'candidate_name' => $lead->name,
            'candidate_phone' => '0999999999', // SĐT khác: vẫn gắn đúng khách nhờ token
            'answers' => [1 => 'A', 2 => 'C', 3 => 'C', 4 => 'My family has four people.'],
            'lead_token' => $token,
        ])->assertRedirect();
        $submission = PlacementTestSubmission::where('customer_id', $lead->id)->firstOrFail();
        $this->assertSame(PlacementTestSubmission::STATUS_PENDING, $submission->status);
        $this->assertSame('khoi_1_2', $submission->grade_group);
        $this->assertEquals(5.0, (float) $submission->listening_score); // 1/2 câu nghe × thang 10
        $this->assertNull($submission->speaking_score);
        $this->assertSame('testing', $lead->fresh()->stage, 'Nộp bài chưa phải "Đã test".');

        // ── 4. Học vụ chấm theo thang khối lớp (Nói nhập tay), chọn lại lớp ─
        $this->actingAs($this->sales)->post(route('placement-tests.results.update', $submission->id), $this->gradePayload())
            ->assertForbidden();
        $this->actingAs($this->academic)->post(route('placement-tests.results.update', $submission->id), $this->gradePayload(['speaking_score' => null]))
            ->assertSessionHasErrors('speaking_score');
        $this->actingAs($this->academic)->post(route('placement-tests.results.update', $submission->id), $this->gradePayload(['listening_score' => 11]))
            ->assertSessionHasErrors('listening_score'); // tối đa 10 với Khối 1 - 2
        $this->assertSame('testing', $lead->fresh()->stage);

        $this->actingAs($this->academic)->post(route('placement-tests.results.update', $submission->id), $this->gradePayload())
            ->assertSessionHasNoErrors();
        $submission->refresh();
        $this->assertSame(PlacementTestSubmission::STATUS_GRADED, $submission->status);
        $this->assertEquals(27.0, (float) $submission->total_score);
        $this->assertSame('STARTERS (FAM 1 _ NÂNG CAO)', $submission->suggested_class);
        $this->assertSame('STARTERS (FAM 1 _ TỪ BÀI 5 - 10)', $submission->finalClass());
        $this->assertTrue($submission->classWasOverridden());
        $this->assertNotEmpty($submission->speaking_comment, 'Nhận xét gợi ý theo băng điểm.');
        $lead->refresh();
        $this->assertSame('tested', $lead->stage);
        $this->assertNotNull($lead->test_score);

        $this->actingAs($this->academic)->post(route('crm.customers.next-stage', $lead->id))->assertSessionHasNoErrors();
        $this->assertSame('result_sent', $lead->fresh()->stage);

        // ── 5. Học thử: CM đặt tối đa 2 buổi của lớp thật ────────────────
        $sessions = ClassSession::where('class_id', $this->activeClass->id)->whereDate('date', '>=', today())->orderBy('date')->take(3)->get();
        $this->actingAs($this->sales)->post(route('crm.customers.trial-bookings.store', $lead->id), ['class_session_ids' => [$sessions[0]->id]])
            ->assertForbidden();
        $this->actingAs($this->academic)->post(route('crm.customers.trial-bookings.store', $lead->id), ['class_session_ids' => $sessions->pluck('id')->all()])
            ->assertSessionHasErrors('class_session_ids');
        $this->actingAs($this->academic)->post(route('crm.customers.trial-bookings.store', $lead->id), [
            'class_session_ids' => [$sessions[0]->id, $sessions[1]->id], 'notes' => 'PH đưa đón',
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, CrmTrialBooking::where('customer_id', $lead->id)->count());
        $this->actingAs($this->academic)->post(route('crm.customers.trial-bookings.store', $lead->id), ['class_session_ids' => [$sessions[2]->id]])
            ->assertSessionHasErrors('class_session_ids');
        $this->assertSame('result_sent', $lead->fresh()->stage, 'Học thử không phải bước pipeline.');

        // ── 6. GV buổi đó nhận xét — lưu theo khách, không theo học viên ──
        $todayBooking = CrmTrialBooking::where('customer_id', $lead->id)->where('class_session_id', $sessions[0]->id)->firstOrFail();
        $this->actingAs($this->teacher)->get(route('teacher.trial-guests'))->assertOk()->assertSee($lead->name);
        $this->actingAs($this->otherTeacher)->post(route('teacher.trial-guests.feedback', $todayBooking), $this->trialFeedbackPayload())
            ->assertForbidden();
        $this->actingAs($this->teacher)->post(route('teacher.trial-guests.feedback', $todayBooking), $this->trialFeedbackPayload())
            ->assertSessionHasNoErrors();
        $todayBooking->refresh();
        $this->assertSame('attended', $todayBooking->status);
        $this->assertSame(4, $todayBooking->rating);
        $this->assertSame('Khá', $todayBooking->remarks['grammar']);
        $this->assertSame($this->teacher->id, $todayBooking->feedback_by);
        $this->assertTrue(CrmCustomerHistory::where('customer_id', $lead->id)->where('type', 'trial')->where('content', 'like', '%Nhận xét học thử (4/5)%')->exists());
        $this->assertSame(0, Student::count(), 'Chưa chốt thì chưa có hồ sơ học viên.');
        $this->actingAs($this->academic)->get(route('crm.customers.show', $lead->id))->assertOk()->assertSee('Con phát âm rõ');

        // ── 7a. Chốt vào lớp còn chỗ, đã đóng học phí đăng ký ─────────────
        $this->actingAs($this->manager)->get(route('crm.closing-wizard', ['customer_id' => $lead->id]))->assertOk();
        $this->actingAs($this->manager)->post(route('crm.closing-wizard.store'), [
            'customer_id' => $lead->id,
            'class_id' => $this->activeClass->id,
            'fee_paid_at_closing' => 1,
            'paid_amount' => 9000000,
            'payment_method' => 'cash',
        ])->assertRedirect(route('crm.customers.won'))->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame('won', $lead->stage);
        $student = Student::findOrFail($lead->converted_student_id);
        $this->assertSame(Student::INITIAL_STATUS, $student->status);
        $this->assertSame('waiting_start', $student->status);
        $this->assertSame($this->activeClass->id, $student->current_class_id);
        $this->assertTrue($student->user->hasRole('student'));
        $this->assertTrue((bool) $student->user->must_change_password);
        $tuition = StudentTuition::where('student_id', $student->id)->firstOrFail();
        $this->assertEquals(9000000, (float) $tuition->final_amount);
        $this->assertSame($this->activeClass->id, $tuition->class_id);
        $this->assertSame(1, TuitionReceipt::where('student_tuition_id', $tuition->id)->where('status', 'pending')->count());
        $enrollment = ClassEnrollment::where('customer_id', $lead->id)->firstOrFail();
        $this->assertSame('pending', $enrollment->status);
        $this->assertFalse(WorkTask::where('title', 'like', 'Nhắc thu học phí%'.$student->code.'%')->exists(), 'Đã đóng phí → không tạo task nhắc thu.');
        $this->assertTrue(CrmCustomerHistory::where('customer_id', $lead->id)->where('type', 'stage_change')->where('from_stage', 'result_sent')->where('to_stage', 'won')->exists());

        // Xác nhận chính thức: thiếu hồ sơ không xác nhận được; đủ → "Đang học" (lớp đã khai giảng).
        $this->actingAs($this->academic)->get(route('crm.confirmations'))->assertOk()->assertSee($student->name);
        $this->actingAs($this->academic)->post(route('crm.enrollments.confirm', $enrollment), [
            'account_sent' => 1, 'zalo_group_added' => 1, 'curriculum_delivered' => 0, 'action' => 'confirm',
        ])->assertSessionHasErrors('enrollment');
        $this->actingAs($this->academic)->post(route('crm.enrollments.confirm', $enrollment), [
            'account_sent' => 1, 'zalo_group_added' => 1, 'curriculum_delivered' => 1, 'action' => 'confirm',
        ])->assertSessionHasNoErrors();
        $this->assertNotNull($enrollment->fresh()->confirmed_at);
        $this->assertSame('studying', $student->fresh()->status);

        // Đã chốt: không lùi, không sang Thất bại (kể cả Admin).
        $this->actingAs($this->admin)->from(route('crm.customers.show', $lead->id))
            ->post(route('crm.customers.stage', $lead->id), ['stage' => 'lost', 'lost_reason' => 'Đổi ý'])
            ->assertSessionHasErrors('stage');
        $this->actingAs($this->admin)->from(route('crm.customers.show', $lead->id))
            ->post(route('crm.customers.stage', $lead->id), ['stage' => 'result_sent', 'reason' => 'Hủy chốt'])
            ->assertSessionHasErrors('stage');
        $this->assertSame('won', $lead->fresh()->stage);
        // Hợp đồng đã khóa: không sửa giá trị hợp đồng.
        $this->actingAs($this->sales)->put(route('crm.customers.update', $lead->id), $this->customerPayload(['deal_value' => 1]))
            ->assertSessionHasErrors('deal_value');

        // ── 7b. Khách thứ hai: nhánh không test, "xếp lớp sau", chưa đóng phí ─
        $this->actingAs($this->sales)->post(route('crm.customers.store'), $this->customerPayload([
            'name' => 'Trần Minh Khoa', 'phone' => '0987 654 302', 'parent_phone' => null,
        ]))->assertRedirect();
        $waiting = CrmCustomer::where('phone_normalized', '0987654302')->firstOrFail();
        $this->actingAs($this->academic)->post(route('crm.customers.next-stage', $waiting->id))->assertSessionHasNoErrors();
        $this->assertSame('consulting', $waiting->fresh()->stage);

        $this->actingAs($this->sales)->post(route('crm.closing-wizard.store'), [
            'customer_id' => $waiting->id, 'fee_paid_at_closing' => 0,
        ])->assertSessionHasErrors('course_id');
        $this->actingAs($this->sales)->post(route('crm.closing-wizard.store'), [
            'customer_id' => $waiting->id, 'course_id' => $this->course->id, 'fee_paid_at_closing' => 0,
        ])->assertRedirect(route('crm.customers.won'))->assertSessionHasNoErrors();

        $waiting->refresh();
        $this->assertSame('waiting_class', $waiting->stage);
        $waitingStudent = Student::findOrFail($waiting->converted_student_id);
        $this->assertNull($waitingStudent->current_class_id);
        $this->assertSame('waiting_start', $waitingStudent->status);
        $waitingTuition = StudentTuition::where('student_id', $waitingStudent->id)->firstOrFail();
        $this->assertNull($waitingTuition->class_id);
        $this->assertEquals(9000000, (float) $waitingTuition->final_amount, 'Chưa có lớp: học phí theo khóa.');
        $this->assertSame(0, ClassEnrollment::where('customer_id', $waiting->id)->count());
        $task = WorkTask::where('title', 'like', 'Nhắc thu học phí%')->where('title', 'like', '%'.$waitingStudent->code.'%')->firstOrFail();
        $this->assertSame($this->sales->id, $task->assignee_id);
        // Chờ xếp lớp cũng là đã chốt: không lùi / không sang Thất bại.
        $this->actingAs($this->admin)->from(route('crm.customers.show', $waiting->id))
            ->post(route('crm.customers.stage', $waiting->id), ['stage' => 'lost', 'lost_reason' => 'Không chờ được'])
            ->assertSessionHasErrors('stage');
        // Kéo tay sang Đã chốt không được — phải gán lớp.
        $this->actingAs($this->academic)->from(route('crm.customers.show', $waiting->id))
            ->post(route('crm.customers.next-stage', $waiting->id))
            ->assertSessionHasErrors('stage');

        // Học vụ gán lớp từ danh sách Chờ xếp lớp.
        $this->actingAs($this->academic)->get(route('crm.waiting-list'))->assertOk()->assertSee('Trần Minh Khoa');
        $this->actingAs($this->sales)->post(route('crm.customers.assign-class', $waiting->id), ['class_id' => $this->upcomingClass->id])
            ->assertForbidden();
        $this->actingAs($this->academic)->post(route('crm.customers.assign-class', $waiting->id), ['class_id' => $this->upcomingClass->id])
            ->assertSessionHasNoErrors();
        $waiting->refresh();
        $this->assertSame('won', $waiting->stage);
        $this->assertSame($this->upcomingClass->id, $waitingStudent->fresh()->current_class_id);
        $this->assertSame($this->upcomingClass->id, $waitingTuition->fresh()->class_id);
        $waitingEnrollment = ClassEnrollment::where('customer_id', $waiting->id)->firstOrFail();

        // Checklist xác nhận chính thức: lớp chưa khai giảng → vẫn "Chờ khai giảng".
        $this->actingAs($this->academic)->get(route('crm.confirmations'))->assertOk()->assertSee('Trần Minh Khoa');
        $this->actingAs($this->academic)->post(route('crm.enrollments.confirm', $waitingEnrollment), [
            'account_sent' => 1, 'zalo_group_added' => 1, 'curriculum_delivered' => 1, 'action' => 'confirm',
        ])->assertSessionHasNoErrors();
        $this->assertNotNull($waitingEnrollment->fresh()->confirmed_at);
        $this->assertSame('waiting_start', $waitingStudent->fresh()->status);

        // Lớp sắp khai giảng còn 1 chỗ: khách thứ ba lấp đầy, khách thứ tư bị chặn vì hết chỗ.
        $third = $this->lead('consulting', '0977000003');
        $this->actingAs($this->manager)->post(route('crm.closing-wizard.store'), [
            'customer_id' => $third->id, 'class_id' => $this->upcomingClass->id, 'fee_paid_at_closing' => 0,
        ])->assertSessionHasNoErrors();
        $fourth = $this->lead('consulting', '0977000004');
        $this->actingAs($this->manager)->post(route('crm.closing-wizard.store'), [
            'customer_id' => $fourth->id, 'class_id' => $this->upcomingClass->id, 'fee_paid_at_closing' => 0,
        ])->assertSessionHasErrors('class_id');
        $this->assertSame('consulting', $fourth->fresh()->stage);
        $this->assertNull($fourth->fresh()->converted_student_id);

        // Báo cáo "Khách chốt thành công" có đủ 3 khách đã chốt.
        $won = $this->actingAs($this->manager)->get(route('crm.customers.won'))->assertOk();
        $this->assertSame(3, $won->viewData('totalCount'));
    }

    public function test_stage_rules_backward_move_and_lost_customers(): void
    {
        $lead = $this->lead('tested', '0977000011');

        // CM không lùi bước.
        $this->actingAs($this->academic)->post(route('crm.customers.stage', $lead->id), ['stage' => 'consulting', 'reason' => 'Nhập nhầm'])
            ->assertForbidden();
        $this->actingAs($this->manager)->postJson(route('crm.customers.stage', $lead->id), ['stage' => 'consulting', 'reason' => 'Nhập nhầm'])
            ->assertForbidden();
        // CM không nhảy cóc sang Chờ xếp lớp / Đã chốt.
        $this->actingAs($this->manager)->postJson(route('crm.customers.stage', $lead->id), ['stage' => 'won'])
            ->assertStatus(422);
        $this->assertSame('tested', $lead->fresh()->stage);

        // Admin lùi bước: bắt buộc lý do, lưu lịch sử.
        $this->actingAs($this->admin)->postJson(route('crm.customers.stage', $lead->id), ['stage' => 'consulting'])
            ->assertStatus(422);
        $this->actingAs($this->admin)->postJson(route('crm.customers.stage', $lead->id), ['stage' => 'consulting', 'reason' => 'Học vụ nhập nhầm điểm test'])
            ->assertOk()->assertJson(['success' => true, 'stage' => 'consulting']);
        $history = CrmCustomerHistory::where('customer_id', $lead->id)->where('type', 'stage_change')->latest('id')->firstOrFail();
        $this->assertSame('tested', $history->from_stage);
        $this->assertSame('consulting', $history->to_stage);
        $this->assertSame('Học vụ nhập nhầm điểm test', $history->reason);
        $this->assertSame($this->admin->id, $history->user_id);

        // Thất bại: bắt buộc lý do; khách Thất bại không mở lại, không xóa, vẫn trong "Khách không chốt".
        $this->actingAs($this->academic)->postJson(route('crm.customers.stage', $lead->id), ['stage' => 'lost'])
            ->assertStatus(422);
        $this->actingAs($this->academic)->postJson(route('crm.customers.stage', $lead->id), ['stage' => 'lost', 'lost_reason' => 'Học phí vượt ngân sách'])
            ->assertOk();
        $lead->refresh();
        $this->assertSame('lost', $lead->stage);
        $this->assertSame('Học phí vượt ngân sách', $lead->lost_reason);
        $this->assertNotNull($lead->lost_at);

        foreach (['consulting', 'new'] as $target) {
            $this->actingAs($this->admin)->postJson(route('crm.customers.stage', $lead->id), ['stage' => $target, 'reason' => 'Khách quay lại'])
                ->assertStatus(422)->assertJsonFragment(['success' => false]);
        }
        $this->actingAs($this->admin)->postJson(route('crm.customers.next-stage', $lead->id))->assertStatus(422);
        $this->actingAs($this->academic)->post(route('crm.customers.trial-bookings.store', $lead->id), [
            'class_session_ids' => [ClassSession::where('class_id', $this->activeClass->id)->whereDate('date', '>=', today())->value('id')],
        ])->assertSessionHasErrors('class_session_ids');
        $this->actingAs($this->manager)->post(route('crm.closing-wizard.store'), [
            'customer_id' => $lead->id, 'class_id' => $this->activeClass->id, 'fee_paid_at_closing' => 0,
        ])->assertSessionHasErrors('customer_id');
        $this->actingAs($this->admin)->delete(route('crm.customers.destroy', $lead->id))->assertSessionHasErrors('customer');
        $this->assertSame('lost', $lead->fresh()->stage);
        $this->assertNull($lead->fresh()->deleted_at);
        $this->actingAs($this->manager)->get(route('crm.lost-deals'))->assertOk()->assertSee($lead->name)->assertSee('Học phí vượt ngân sách');
    }

    public function test_branch_scoping_hides_other_branch_customers(): void
    {
        $lead = $this->lead('result_sent', '0977000021');
        $otherSales = $this->userWithRole('sales_consultant', $this->branch);

        // Quản lý / Học vụ chi nhánh khác không thấy, không thao tác được.
        $this->actingAs($this->otherManager)->get(route('crm.customers.show', $lead->id))->assertNotFound();
        $this->actingAs($this->otherManager)->post(route('crm.customers.next-stage', $lead->id))->assertNotFound();
        $this->actingAs($this->otherManager)->post(route('crm.closing-wizard.store'), [
            'customer_id' => $lead->id, 'class_id' => $this->activeClass->id, 'fee_paid_at_closing' => 0,
        ])->assertNotFound();
        $pipeline = $this->actingAs($this->otherManager)->get(route('crm.pipeline'))->assertOk();
        $this->assertSame(0, collect($pipeline->viewData('stages'))->sum('count'));
        $this->actingAs($this->otherManager)->get(route('crm.customers.index'))->assertOk()->assertDontSee($lead->name);

        // Sale chỉ thấy khách được giao.
        $this->actingAs($otherSales)->get(route('crm.customers.show', $lead->id))->assertNotFound();
        $this->actingAs($this->sales)->get(route('crm.customers.show', $lead->id))->assertOk();

        // Quản lý cùng chi nhánh thấy.
        $pipeline = $this->actingAs($this->manager)->get(route('crm.pipeline'))->assertOk();
        $this->assertSame(1, collect($pipeline->viewData('stages'))->firstWhere('id', 'result_sent')['count']);

        // Lớp chi nhánh khác không nhận khách của chi nhánh này.
        $otherCourseClass = ClassModel::create([
            'code' => 'NK-FAM1', 'name' => 'Lớp chi nhánh khác', 'course_id' => $this->course->id, 'branch_id' => $this->otherBranch->id,
            'max_capacity' => 10, 'status' => 'active', 'start_date' => today()->subWeek(),
        ]);
        $this->actingAs($this->manager)->post(route('crm.closing-wizard.store'), [
            'customer_id' => $lead->id, 'class_id' => $otherCourseClass->id, 'fee_paid_at_closing' => 0,
        ])->assertSessionHasErrors('class_id');
        $this->assertSame('result_sent', $lead->fresh()->stage);
    }

    /**
     * Sĩ số khi chốt / gán lớp phải khớp danh sách lớp thật (ClassModel::roster — lớp hiện tại + lượt xếp lớp còn hiệu lực,
     * chỉ học viên còn giữ chỗ), giống màn Lớp học. Trước đây CRM chỉ đếm class_enrollments nên:
     * học viên xếp lớp từ hồ sơ (chỉ có current_class_id) không bị tính → nhận quá sĩ số; học viên Bảo lưu vẫn bị tính → báo đầy sai.
     */
    public function test_closing_and_assign_class_count_seats_from_the_real_class_roster(): void
    {
        // Lớp 2 chỗ: 1 học viên xếp từ hồ sơ học viên (chỉ có current_class_id, không qua CRM).
        Student::create(['code' => 'HV-R1', 'name' => 'HV xếp tay', 'phone' => '0900000101', 'branch_id' => $this->branch->id, 'current_class_id' => $this->upcomingClass->id, 'status' => 'waiting_start']);
        // Học viên Bảo lưu có lượt xếp lớp ở lớp đang học: không giữ chỗ.
        $deferred = Student::create(['code' => 'HV-R2', 'name' => 'HV bảo lưu', 'phone' => '0900000102', 'branch_id' => $this->branch->id, 'status' => 'deferred']);
        ClassEnrollment::create(['student_id' => $deferred->id, 'class_id' => $this->activeClass->id, 'status' => 'completed']);
        $this->assertSame(10, $this->activeClass->seatsLeft());
        $this->assertSame(1, $this->upcomingClass->seatsLeft());

        $wizard = $this->actingAs($this->manager)->get(route('crm.closing-wizard'))->assertOk();
        $offered = $wizard->viewData('classes')->firstWhere('id', $this->upcomingClass->id);
        $this->assertNotNull($offered, 'Lớp còn 1 chỗ theo danh sách lớp phải được gợi ý.');
        $this->assertSame(1, $offered->remaining_seats);
        $this->assertSame(10, $wizard->viewData('classes')->firstWhere('id', $this->activeClass->id)->remaining_seats);

        $first = $this->lead('consulting', '0977000031');
        $this->actingAs($this->manager)->post(route('crm.closing-wizard.store'), [
            'customer_id' => $first->id, 'class_id' => $this->upcomingClass->id, 'fee_paid_at_closing' => 0,
        ])->assertSessionHasNoErrors();
        $this->assertSame('won', $first->fresh()->stage);
        $this->assertTrue($this->upcomingClass->fresh()->isFull());

        $second = $this->lead('consulting', '0977000032');
        $this->actingAs($this->manager)->post(route('crm.closing-wizard.store'), [
            'customer_id' => $second->id, 'class_id' => $this->upcomingClass->id, 'fee_paid_at_closing' => 0,
        ])->assertSessionHasErrors('class_id');
        $this->assertNull($this->actingAs($this->manager)->get(route('crm.closing-wizard'))->viewData('classes')->firstWhere('id', $this->upcomingClass->id));

        // Gán lớp từ Chờ xếp lớp cũng dùng cùng sĩ số.
        $this->actingAs($this->manager)->post(route('crm.closing-wizard.store'), [
            'customer_id' => $second->id, 'course_id' => $this->course->id, 'fee_paid_at_closing' => 0,
        ])->assertSessionHasNoErrors();
        $this->assertSame('waiting_class', $second->fresh()->stage);
        $this->actingAs($this->academic)->post(route('crm.customers.assign-class', $second->id), ['class_id' => $this->upcomingClass->id])
            ->assertSessionHasErrors('class_id');
        $this->assertSame('waiting_class', $second->fresh()->stage);
        $matches = $this->actingAs($this->academic)->get(route('crm.waiting-list'))->assertOk()->viewData('matchingClassesByLead');
        $this->assertFalse($matches[$second->id]->contains('id', $this->upcomingClass->id));
        $this->assertTrue($matches[$second->id]->contains('id', $this->activeClass->id));
    }

    /** Sĩ số nạp sẵn cho danh sách lớp khi lớp chỉ có lượt xếp lớp (không học viên nào có current_class_id) — trước đây lỗi 500. */
    public function test_roster_counts_load_for_classes_with_only_enrollments(): void
    {
        $student = Student::create(['code' => 'HV-E1', 'name' => 'HV ghi danh', 'phone' => '0900000103', 'branch_id' => $this->branch->id, 'status' => 'waiting_start']);
        ClassEnrollment::create(['student_id' => $student->id, 'class_id' => $this->upcomingClass->id, 'status' => 'pending']);

        $classes = ClassModel::whereKey([$this->upcomingClass->id, $this->activeClass->id])->get();
        ClassModel::loadRosterCounts($classes);

        $this->assertSame(1, $classes->firstWhere('id', $this->upcomingClass->id)->roster_count);
        $this->assertSame(0, $classes->firstWhere('id', $this->activeClass->id)->roster_count);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function makeClassWithSessions(string $code, string $status, Carbon $from, Carbon $to, array $attributes = []): ClassModel
    {
        $class = ClassModel::create(array_merge([
            'code' => $code, 'name' => 'Lớp '.$code, 'course_id' => $this->course->id, 'branch_id' => $this->branch->id,
            'program' => 'Starters', 'level' => 'STARTERS FAM 1', 'teacher_id' => $this->teacher->id, 'room' => 'P1',
            'max_capacity' => 10, 'min_students' => 2, 'status' => $status, 'tuition_fee' => 9000000,
            'start_date' => $from, 'end_date' => $to,
        ], $attributes));

        $slots = collect(SessionScheduleService::DAY_MAP)->keys()
            ->map(fn (string $day) => ['day' => $day, 'start' => $status === 'active' ? '17:30' : '19:30', 'end' => $status === 'active' ? '19:00' : '21:00', 'shift' => 'Ca tối'])
            ->all();
        foreach (app(SessionScheduleService::class)->generate($slots, $from, $to, $this->branch->id) as $session) {
            ClassSession::create([
                'class_id' => $class->id, 'branch_id' => $class->branch_id, 'date' => $session['date'],
                'shift_name' => $session['shift'], 'start_time' => $session['start'], 'end_time' => $session['end'],
                'room' => $class->room, 'teacher_id' => $class->teacher_id, 'status' => 'scheduled',
            ]);
        }

        return $class;
    }

    private function customerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nguyễn Gia Bảo',
            'phone' => '0912.345.601',
            'parent_name' => 'Nguyễn Thu Hà',
            'parent_phone' => '0912345699',
            'source' => 'Facebook Ads',
            'branch_id' => $this->branch->id,
            'course_interest' => 'Starters FAM 1',
            'deal_value' => 9000000,
            'next_follow_up_at' => now()->addDay()->format('Y-m-d H:i'),
        ], $overrides);
    }

    private function gradePayload(array $overrides = []): array
    {
        return array_merge([
            'grade_group' => 'khoi_1_2',
            'listening_score' => 8,
            'reading_writing_score' => 12,
            'speaking_score' => 7,
            'chosen_class' => 'STARTERS (FAM 1 _ TỪ BÀI 5 - 10)',
            'teacher_comments' => 'Nên học lớp FAM 1 từ bài 5.',
        ], $overrides);
    }

    private function trialFeedbackPayload(): array
    {
        return [
            'status' => 'attended',
            'rating' => 4,
            'remarks' => ['grammar' => 'Khá', 'attitude' => 'Hăng hái', 'result' => 'Theo kịp lớp'],
            'feedback' => 'Con phát âm rõ, mạnh dạn phát biểu.',
        ];
    }

    private function lead(string $stage, string $phone): CrmCustomer
    {
        return CrmCustomer::create([
            'code' => CrmCustomer::generateCode(),
            'name' => 'Khách '.$phone,
            'phone' => $phone,
            'phone_normalized' => $phone,
            'branch_id' => $this->branch->id,
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
