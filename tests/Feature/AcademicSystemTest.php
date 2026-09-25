<?php

namespace Tests\Feature;

use App\Models\BigTest;
use App\Models\BigTestResult;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\CrmCustomer;
use App\Models\DebtReminderRule;
use App\Models\PlacementTest;
use App\Models\PlacementTestSubmission;
use App\Models\Student;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\SyllabusCurriculum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AcademicSystemTest extends TestCase
{
    use RefreshDatabase;

    private User $academicHead;

    private User $teacher;

    private Branch $branch;

    private Course $course;

    private ClassModel $classModel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create([
            'name' => 'Chi nhánh Hà Đông',
            'code' => 'HD',
            'address' => 'Số 88 Quang Trung, Hà Đông, Hà Nội',
            'phone' => '02455556666',
            'is_active' => true,
        ]);

        $this->academicHead = User::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Trưởng phòng Học thuật',
            'is_active' => true,
        ]);
        $this->academicHead->assignRole('academic_lead');

        $this->teacher = User::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Giáo viên Chấm thi',
            'is_active' => true,
        ]);
        $this->teacher->assignRole('teacher');

        $this->course = Course::create([
            'code' => 'IELTS-70',
            'name' => 'IELTS Chuyên Sâu 7.0+',
            'tuition_fee' => 16000000,
            'duration_months' => 4,
            'is_active' => true,
        ]);

        $this->classModel = ClassModel::create([
            'code' => 'IE-HD-01',
            'name' => 'Lớp IELTS 7.0 HD01',
            'course_id' => $this->course->id,
            'branch_id' => $this->branch->id,
            'teacher_id' => $this->teacher->id,
            'status' => 'active',
        ]);
    }

    // =========================================================================
    // a. Course level creation, update, and deletion
    // =========================================================================

    public function test_can_create_update_and_delete_course_level(): void
    {
        // 1. Create course level
        $payload = [
            'code' => 'LV-B2-UPPER',
            'name' => 'Upper-Intermediate B2+',
            'target' => 'IELTS 6.0 - 6.5, CEFR B2',
            'duration' => '3.5 Tháng (42 Buổi)',
            'lessons_count' => 42,
        ];

        $response = $this->actingAs($this->academicHead)->post(route('course-levels.store'), $payload);
        $response->assertRedirect(route('course-levels.index'));

        $this->assertDatabaseHas('course_levels', [
            'code' => 'LV-B2-UPPER',
            'name' => 'Upper-Intermediate B2+',
            'target' => 'IELTS 6.0 - 6.5, CEFR B2',
            'lessons_count' => 42,
            'is_active' => true,
        ]);

        $level = CourseLevel::where('code', 'LV-B2-UPPER')->first();
        $this->assertNotNull($level);

        // 2. Update course level
        $responseUpdate = $this->actingAs($this->academicHead)->put(route('course-levels.update', $level->id), [
            'name' => 'Upper-Intermediate B2+ (Nâng cao)',
            'target' => 'IELTS 6.5 Target',
            'duration' => '4 Tháng (48 Buổi)',
            'lessons_count' => 48,
        ]);
        $responseUpdate->assertRedirect(route('course-levels.index'));

        $level->refresh();
        $this->assertEquals('Upper-Intermediate B2+ (Nâng cao)', $level->name);
        $this->assertEquals(48, $level->lessons_count);

        // 3. Delete course level
        $responseDelete = $this->actingAs($this->academicHead)->delete(route('course-levels.destroy', $level->id));
        $responseDelete->assertRedirect(route('course-levels.index'));
        $this->assertDatabaseMissing('course_levels', ['id' => $level->id]);
    }

    public function test_course_level_creation_fails_on_duplicate_code(): void
    {
        CourseLevel::create([
            'code' => 'LV-EXISTING',
            'name' => 'Trình độ đã có',
            'target' => 'Mục tiêu',
            'lessons_count' => 30,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->academicHead)->post(route('course-levels.store'), [
            'code' => 'LV-EXISTING',
            'name' => 'Trình độ mới trùng mã',
            'target' => 'Mục tiêu mới',
            'lessons_count' => 36,
        ]);

        $response->assertSessionHasErrors(['code']);
    }

    // =========================================================================
    // b. Placement test creation and grading (Listening, Reading, Writing, Speaking, Overall)
    // =========================================================================

    public function test_can_create_placement_test_and_grade_candidate_submission(): void
    {
        // 1. Create placement test
        $testPayload = [
            'code' => 'TEST-IELTS-DIAGNOSTIC-2026',
            'title' => 'Bài Test Đánh Giá Năng Lực IELTS Toàn Diện',
            'target_level' => 'IELTS 5.5 - 7.5',
            'duration_minutes' => 90,
            'questions_count' => 80,
        ];

        $responseTest = $this->actingAs($this->academicHead)->post(route('placement-tests.store'), $testPayload);
        $responseTest->assertRedirect(route('placement-tests.index'));

        $test = PlacementTest::where('code', 'TEST-IELTS-DIAGNOSTIC-2026')->first();
        $this->assertNotNull($test);
        $this->assertTrue($test->is_active);

        // 2. Candidate submits test
        $customer = CrmCustomer::create([
            'code' => 'KH-TEST01',
            'name' => 'Võ Minh Quân',
            'phone' => '0933111222',
            'stage' => 'tested',
            // Phase 1: chấm bài theo phạm vi khách CRM — Học thuật chỉ chấm khách chi nhánh mình.
            'branch_id' => $this->branch->id,
        ]);

        $submission = PlacementTestSubmission::create([
            'placement_test_id' => $test->id,
            'customer_id' => $customer->id,
            'candidate_name' => $customer->name,
            'candidate_phone' => $customer->phone,
            'status' => 'submitted',
        ]);

        // 3. Teacher grades submission (Listening, Reading, Writing, Speaking)
        $gradePayload = [
            'listening_score' => 7.0,
            'reading_score' => 6.5,
            'writing_score' => 6.0,
            'speaking_score' => 6.5,
            'cefr_level' => 'B2',
            'recommended_course' => 'IELTS Chuyên Sâu 7.0+',
            'teacher_comments' => 'Phát âm tốt, ngữ pháp tương đối chuẩn, cần bổ sung từ vựng Writing Task 2.',
        ];

        $responseGrade = $this->actingAs($this->academicHead)->post(route('placement-tests.results.update', $submission->id), $gradePayload);
        $responseGrade->assertRedirect(route('placement-tests.results.show', $submission->id));

        $submission->refresh();
        $this->assertEquals('graded', $submission->status);
        $this->assertEquals($this->academicHead->id, $submission->grader_id);
        $this->assertEquals('B2', $submission->cefr_level);
        $this->assertEquals(7.0, $submission->listening_score);
        $this->assertEquals(6.5, $submission->reading_score);
        $this->assertEquals(6.0, $submission->writing_score);
        $this->assertEquals(6.5, $submission->speaking_score);

        // Verify Overall Score computation: (7.0 + 6.5 + 6.0 + 6.5) / 4 = 6.5
        $this->assertEquals(6.5, $submission->overall_score);

        // 4. View rubric guide
        $responseRubric = $this->actingAs($this->academicHead)->get(route('placement-tests.rubric-guide'));
        $responseRubric->assertOk();
    }

    public function test_placement_test_crud_management(): void
    {
        $test = PlacementTest::create([
            'code' => 'TEST-TOEIC-2026',
            'title' => 'Đề Thi TOEIC 4 Kỹ Năng',
            'target_level' => 'TOEIC 650+',
            'duration_minutes' => 60,
            'questions_count' => 50,
            'is_active' => true,
        ]);

        // Edit view
        $responseEdit = $this->actingAs($this->academicHead)->get(route('placement-tests.edit', $test->id));
        $responseEdit->assertOk();

        // Update
        $responseUpdate = $this->actingAs($this->academicHead)->put(route('placement-tests.update', $test->id), [
            'title' => 'Đề Thi TOEIC 4 Kỹ Năng (Cập nhật 2026)',
            'target_level' => 'TOEIC 750+',
            'duration_minutes' => 75,
            'questions_count' => 60,
            'is_active' => 1,
        ]);
        $responseUpdate->assertRedirect(route('placement-tests.index'));
        $test->refresh();
        $this->assertEquals('Đề Thi TOEIC 4 Kỹ Năng (Cập nhật 2026)', $test->title);
        $this->assertEquals(75, $test->duration_minutes);

        // Delete
        $responseDelete = $this->actingAs($this->academicHead)->delete(route('placement-tests.destroy', $test->id));
        $responseDelete->assertRedirect(route('placement-tests.index'));
        $this->assertDatabaseMissing('placement_tests', ['id' => $test->id]);
    }

    public function test_lead_online_test_portal_auto_grading_and_smart_comments(): void
    {
        $test = PlacementTest::create([
            'code' => 'PORTAL-TEST-2026',
            'title' => 'Đề Test Online Trực Tuyến 4 Kỹ Năng',
            'target_level' => 'B1 - B2',
            'duration_minutes' => 45,
            'questions_count' => 4,
            'is_active' => true,
            'questions' => [
                ['id' => 1, 'skill' => 'listening', 'type' => 'multiple_choice', 'title' => 'L1', 'options' => [['key' => 'A', 'text' => 'a'], ['key' => 'B', 'text' => 'b']], 'correct_answer' => 'B'],
                ['id' => 2, 'skill' => 'listening', 'type' => 'multiple_choice', 'title' => 'L2', 'options' => [['key' => 'A', 'text' => 'a'], ['key' => 'B', 'text' => 'b']], 'correct_answer' => 'A'],
                ['id' => 3, 'skill' => 'reading', 'type' => 'multiple_choice', 'title' => 'R1', 'options' => [['key' => 'C', 'text' => 'c'], ['key' => 'D', 'text' => 'd']], 'correct_answer' => 'C'],
                ['id' => 4, 'skill' => 'reading', 'type' => 'multiple_choice', 'title' => 'R2', 'options' => [['key' => 'C', 'text' => 'c'], ['key' => 'D', 'text' => 'd']], 'correct_answer' => 'D'],
            ],
        ]);

        $lead = CrmCustomer::create([
            'code' => 'KH-ONLINE01',
            'name' => 'Lê Thanh Hằng',
            'phone' => '0977 888 999',
            'phone_normalized' => '0977888999',
            'email' => 'thanhhang.le@gmail.com',
            'stage' => 'test_scheduled',
            'branch_id' => $this->branch->id,
        ]);

        // 1. Lead mở link test riêng (có chữ ký, do CRM sinh)
        $signedUrl = app(\App\Services\PlacementPortalLinkService::class)->signedLinkForLead($test, $lead);
        $responseTake = $this->get($signedUrl);
        $responseTake->assertOk();
        $responseTake->assertSee('Lê Thanh Hằng');
        preg_match('/name="lead_token" value="([^"]+)"/', $responseTake->getContent(), $m);
        $this->assertNotEmpty($m[1] ?? null);

        // 2. Lead nộp bài
        $responseSubmit = $this->post(route('portal.test.submit', $test->code), [
            'lead_token' => html_entity_decode($m[1]),
            'candidate_name' => $lead->name,
            'candidate_phone' => $lead->phone,
            'candidate_email' => $lead->email,
            'answers' => ['1' => 'B', '2' => 'A', '3' => 'C', '4' => 'C'],
            'writing_content' => 'I would like to improve my English speaking and listening skills.',
            'speaking_self_rate' => 'intermediate',
        ]);

        $submission = PlacementTestSubmission::where('candidate_phone', '0977 888 999')->first();
        $this->assertNotNull($submission);
        $responseSubmit->assertRedirect(URL::signedRoute('portal.test.scorecard', ['id' => $submission->id]));

        // 3. Nghe/Đọc chấm tự động theo đáp án của đề; Viết/Nói chờ Học vụ chấm
        $this->assertSame($lead->id, $submission->customer_id);
        $this->assertEquals(8.5, $submission->listening_score); // 2/2
        $this->assertEquals(4.5, $submission->reading_score);   // 1/2
        $this->assertNull($submission->writing_score);
        $this->assertNull($submission->speaking_score);
        $this->assertNull($submission->overall_score);
        $this->assertSame('pending', $submission->status);
        $this->assertNull($submission->grader_id);

        // 4. Lead ở "Test" (mở link / nộp bài) — chỉ sang "Đã test" khi Học vụ chấm xong; chưa có điểm tổng
        $lead->refresh();
        $this->assertEquals('testing', $lead->stage);
        $this->assertNull($lead->test_score);

        // 5. Học vụ chấm hoàn tất -> rubric sinh nhận xét, đồng bộ điểm sang CRM
        $this->actingAs($this->academicHead)->post(route('placement-tests.results.update', $submission->id), [
            'listening_score' => 8.5,
            'reading_score' => 4.5,
            'writing_score' => 6.0,
            'speaking_score' => 6.0,
            'cefr_level' => 'B1',
        ])->assertRedirect();
        $submission->refresh();
        $this->assertSame('graded', $submission->status);
        $this->assertEquals(6.3, $submission->overall_score);
        $this->assertStringContainsString('Kỹ năng Nghe', $submission->teacher_comments);
        $this->assertStringContainsString('6.3', $lead->fresh()->test_score);

        // 6. View candidate scorecard (public route yêu cầu URL có chữ ký)
        $responseScorecard = $this->get(URL::signedRoute('portal.test.scorecard', ['id' => $submission->id]));
        $responseScorecard->assertOk();

        // Link không chữ ký phải bị chặn để không dò id tuần tự
        $this->get(route('portal.test.scorecard', $submission->id))->assertForbidden();
    }

    // =========================================================================
    // c. Syllabus curriculum, Unit builder, Assignment, Adjustment request
    // =========================================================================

    public function test_syllabus_curriculum_and_unit_builder_workflow(): void
    {
        // 1. Store curriculum document
        $docPayload = [
            'code' => 'CURR-IE-70-V2',
            'title' => 'Giáo trình IELTS Intensive 7.0 Phiên bản 2026',
            'course_id' => $this->course->id,
            'version' => 'v2.0',
        ];

        // Giáo trình tạo ở màn Soạn syllabus (màn Tài liệu chỉ nhận file thật — xem Phase2SyllabusTest).
        $responseDoc = $this->actingAs($this->academicHead)->post(route('syllabus.curriculums.store'), $docPayload);
        $responseDoc->assertRedirect();

        $this->assertDatabaseHas('syllabus_curriculums', [
            'code' => 'CURR-IE-70-V2',
            'title' => 'Giáo trình IELTS Intensive 7.0 Phiên bản 2026',
            'version' => 'v2.0',
        ]);

        $curriculum = SyllabusCurriculum::where('code', 'CURR-IE-70-V2')->first();
        $this->assertNotNull($curriculum);

        // 2. Unit builder
        $unitPayload = [
            'curriculum_id' => $curriculum->id,
            'unit_number' => 1,
            'title' => 'Unit 1: Environment & Climate Change Vocabulary & Writing Task 2 Problem-Solution',
            'objectives' => 'Nắm chắc 30 từ vựng chủ đề môi trường và cấu trúc viết Problem-Solution Essay',
            'vocabulary_focus' => 'biodiversity, greenhouse gases, sustainable development, carbon footprint',
            'grammar_focus' => 'Inversion sentences, Relative clauses with prepositions',
            'homework_guide' => 'Hoàn thành bài viết 250 từ về Global Warming và nộp qua LMS',
        ];

        $responseUnit = $this->actingAs($this->academicHead)->post(route('syllabus.units.store'), $unitPayload);
        $responseUnit->assertRedirect(route('syllabus.builder', ['curriculum' => $curriculum->id]));

        $this->assertDatabaseHas('syllabus_units', [
            'curriculum_id' => $curriculum->id,
            'unit_number' => 1,
            'title' => 'Unit 1: Environment & Climate Change Vocabulary & Writing Task 2 Problem-Solution',
        ]);

        // 3. Assign curriculum chapter preparation to teacher
        $assignPayload = [
            'user_id' => $this->teacher->id,
            'curriculum_id' => $curriculum->id,
            'class_id' => $this->classModel->id,
            'assigned_chapters' => 'Soạn thảo Unit 1 đến Unit 5',
            'stage_name' => 'Chặng 1 · Foundation',
            'deadline' => '2026-09-15',
        ];

        $responseAssign = $this->actingAs($this->academicHead)->post(route('syllabus.assignments.store'), $assignPayload);
        $responseAssign->assertRedirect(route('syllabus.assignments'));

        $this->assertDatabaseHas('syllabus_assignments', [
            'user_id' => $this->teacher->id,
            'curriculum_id' => $curriculum->id,
            'assigned_chapters' => 'Soạn thảo Unit 1 đến Unit 5',
            'status' => 'in_progress',
        ]);
    }

    public function test_syllabus_adjustment_request_approval_and_rejection(): void
    {
        // 1. Submit adjustment request
        $responseReq = $this->actingAs($this->teacher)->post(route('syllabus.adjustment-requests.store'), [
            'class_id' => $this->classModel->id,
            'request_type' => 'Gia hạn thêm 2 buổi học ôn Writing trước Big Test',
            'reason' => 'Học viên lớp tiếp thu phần essay hơi chậm so với dự kiến',
        ]);

        $responseReq->assertRedirect(route('syllabus.adjustment-requests'));

        $this->assertDatabaseHas('syllabus_adjustment_requests', [
            'class_id' => $this->classModel->id,
            'user_id' => $this->teacher->id,
            'request_type' => 'Gia hạn thêm 2 buổi học ôn Writing trước Big Test',
            'status' => 'pending',
        ]);

        $adjReq = SyllabusAdjustmentRequest::where('class_id', $this->classModel->id)->first();
        $this->assertNotNull($adjReq);

        // 2. Approve request
        $responseApprove = $this->actingAs($this->academicHead)->post(route('syllabus.adjustment-requests.approve', $adjReq->id));
        $responseApprove->assertRedirect();

        $adjReq->refresh();
        $this->assertEquals('approved', $adjReq->status);
        $this->assertEquals($this->academicHead->id, $adjReq->approver_id);

        // 3. Reject another adjustment request
        $adjReq2 = SyllabusAdjustmentRequest::create([
            'class_id' => $this->classModel->id,
            'user_id' => $this->teacher->id,
            'request_type' => 'Đổi giáo trình giữa chừng',
            'reason' => 'Không phù hợp',
            'status' => 'pending',
        ]);

        // Từ chối bắt buộc có lý do và lý do được lưu lại.
        $this->actingAs($this->academicHead)->post(route('syllabus.adjustment-requests.reject', $adjReq2->id))
            ->assertSessionHasErrors('rejection_reason');
        $this->assertEquals('pending', $adjReq2->fresh()->status);

        $responseReject = $this->actingAs($this->academicHead)->post(route('syllabus.adjustment-requests.reject', $adjReq2->id), [
            'rejection_reason' => 'Không phù hợp với lộ trình',
        ]);
        $responseReject->assertRedirect();

        $adjReq2->refresh();
        $this->assertEquals('rejected', $adjReq2->status);
        $this->assertEquals('Không phù hợp với lộ trình', $adjReq2->rejection_reason);
    }

    // =========================================================================
    // d. Big test distribution and parent Zalo notification
    // =========================================================================

    public function test_big_test_distribution_and_parent_zalo_notification(): void
    {
        // 1. Distribute big test
        $bigTestPayload = [
            'title' => 'Big Test Giữa Khóa - IELTS Intensive Midterm 2026',
            'class_id' => $this->classModel->id,
            'test_type' => 'Midterm Test (Giữa kỳ)',
            'scheduled_at' => '2026-08-30 08:30:00',
            'room' => 'Phòng Lab 302 - Cơ sở Hà Đông',
        ];

        $responseBT = $this->actingAs($this->academicHead)->post(route('syllabus.big-tests.store'), $bigTestPayload);
        $responseBT->assertRedirect(route('syllabus.big-tests.distribution'));

        $this->assertDatabaseHas('big_tests', [
            'title' => 'Big Test Giữa Khóa - IELTS Intensive Midterm 2026',
            'class_id' => $this->classModel->id,
            'test_type' => 'Midterm Test (Giữa kỳ)',
            'room' => 'Phòng Lab 302 - Cơ sở Hà Đông',
            'is_distributed' => false,
            'status' => 'draft',
        ]);

        $bigTest = BigTest::where('class_id', $this->classModel->id)->first();
        $this->assertNotNull($bigTest);
        $this->assertStringStartsWith('BT-', $bigTest->code);
        $this->assertNotEmpty($bigTest->passcode);
        $this->actingAs($this->academicHead)->post(route('syllabus.big-tests.approve', $bigTest->id))->assertRedirect();
        $bigTest->refresh();
        $this->assertTrue($bigTest->is_distributed);

        // 2. Create student & big test results
        $student = Student::create([
            'code' => 'HV-BT01',
            'name' => 'Ngô Thị Thanh Trúc',
            'phone' => '0966555444',
            'current_class_id' => $this->classModel->id,
            'branch_id' => $this->branch->id,
            'status' => 'studying',
        ]);

        $result = BigTestResult::create([
            'big_test_id' => $bigTest->id,
            'student_id' => $student->id,
            'score' => 8.0,
            'parent_notified' => false,
            'status' => 'approved',
        ]);

        // 3. Send Zalo results notification to parents
        $responseZalo = $this->actingAs($this->academicHead)->post(route('syllabus.big-tests.send-zalo', $bigTest->id));
        $responseZalo->assertRedirect();

        $result->refresh();
        $this->assertTrue($result->parent_notified);
        $this->assertNotNull($result->notified_at);
    }

    // =========================================================================
    // e. Bank accounts VietQR and Debt reminder templates
    // =========================================================================

    public function test_can_configure_bank_accounts_and_debt_reminders(): void
    {
        // Cấu hình hệ thống yêu cầu quyền bank_account.manage / fee_reminder_config.manage
        // (chỉ admin + accountant) — trước đây route bị bỏ ngỏ nên test dùng academic_lead.
        $configAdmin = User::factory()->create(['is_active' => true]);
        $configAdmin->assignRole('admin');

        // 1. Create bank account
        $bankPayload = [
            'bank_code' => 'MBBANK',
            'bank_name' => 'Ngân hàng Quân Đội (MB Bank)',
            'account_number' => '09887766554433',
            'account_holder' => 'TRUNG TAM TIENG ANH MENGLISH',
            'branch_location' => 'Hà Đông, Hà Nội',
            'branch_id' => $this->branch->id,
        ];

        $responseBank = $this->actingAs($configAdmin)->post(route('system-config.bank-accounts.store'), $bankPayload);
        $responseBank->assertRedirect(route('system-config.bank-accounts'));

        $this->assertDatabaseHas('bank_accounts', [
            'bank_code' => 'MBBANK',
            'account_number' => '09887766554433',
            'account_holder' => 'TRUNG TAM TIENG ANH MENGLISH',
            'is_active' => true,
        ]);

        // 2. Create / Upsert debt reminder template
        $debtPayload = [
            'milestone_key' => 'd_plus_7',
            'title' => 'Nhắc nợ quá hạn 7 ngày (D+7 ZNS SMS Template)',
            'template_content' => 'Kính gửi Phụ huynh/Học viên {ten_hoc_vien}, học phí lớp {ten_lop} đã quá hạn 7 ngày. Vui lòng hoàn tất thanh toán trước ngày {han_chot} để duy trì lịch học.',
        ];

        $responseDebt = $this->actingAs($configAdmin)->post(route('system-config.debt-reminders.store'), $debtPayload);
        $responseDebt->assertRedirect(route('system-config.debt-reminders'));

        $this->assertDatabaseHas('debt_reminder_rules', [
            'milestone_key' => 'd_plus_7',
            'title' => 'Nhắc nợ quá hạn 7 ngày (D+7 ZNS SMS Template)',
            'is_enabled' => true,
        ]);

        // 3. Upsert update existing template
        $updatePayload = [
            'milestone_key' => 'd_plus_7',
            'title' => 'Nhắc nợ quá hạn 7 ngày (Phiên bản Zalo ZNS Official)',
            'template_content' => 'Nội dung mẫu ZNS cập nhật mới nhất.',
        ];

        $this->actingAs($configAdmin)->post(route('system-config.debt-reminders.store'), $updatePayload);

        $this->assertDatabaseHas('debt_reminder_rules', [
            'milestone_key' => 'd_plus_7',
            'title' => 'Nhắc nợ quá hạn 7 ngày (Phiên bản Zalo ZNS Official)',
        ]);
        $this->assertCount(1, DebtReminderRule::where('milestone_key', 'd_plus_7')->get());
    }
}
