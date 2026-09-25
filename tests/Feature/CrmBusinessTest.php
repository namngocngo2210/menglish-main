<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\PlacementTest;
use App\Models\Promotion;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmBusinessTest extends TestCase
{
    use RefreshDatabase;

    private User $salesUser;

    private Branch $branch;

    private Course $course;

    private ClassModel $classModel;

    private BankAccount $bankAccount;

    private User $examiner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create([
            'name' => 'Cơ sở Thanh Xuân',
            'code' => 'TX',
            'address' => 'Số 20 Nguyễn Trãi, Thanh Xuân, Hà Nội',
            'phone' => '02499998888',
            'is_active' => true,
        ]);

        $this->salesUser = User::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Chuyên viên Tuyển sinh',
            'is_active' => true,
        ]);
        $this->salesUser->assignRole('sales_consultant');
        $this->examiner = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->examiner->assignRole('academic_staff');

        $this->course = Course::create([
            'code' => 'IELTS-PRO',
            'name' => 'IELTS Pro 6.5 - 7.5',
            'tuition_fee' => 15000000,
            'duration_months' => 4,
            'is_active' => true,
        ]);

        $this->classModel = ClassModel::create([
            'code' => 'IE-TX-01',
            'name' => 'Lớp IELTS Intensive TX01',
            'course_id' => $this->course->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $this->bankAccount = BankAccount::create([
            'bank_code' => 'VCB', 'bank_name' => 'Vietcombank', 'account_number' => '123456789',
            'account_holder' => 'MENGLISH', 'branch_id' => $this->branch->id,
            'is_default_vietqr' => true, 'is_active' => true,
        ]);
    }

    // =========================================================================
    // a. Lead creation with full input fields & business validation
    // =========================================================================

    public function test_can_create_lead_with_all_business_fields(): void
    {
        $payload = [
            'name' => 'Hoàng Nhật Minh',
            'phone' => '0977 123 456',
            'email' => 'nhatminh.hoang@gmail.com',
            'dob' => '2004-11-20',
            'gender' => 'Nam',
            'address' => 'P402 Tòa nhà Golden Land, Thanh Xuân, Hà Nội',
            'branch_id' => $this->branch->id,
            'course_interest' => 'IELTS Pro 6.5 - 7.5',
            'source' => 'Facebook Ads',
            'assigned_user_id' => $this->salesUser->id,
            'deal_value' => 15000000,
            'notes' => 'Học viên mục tiêu 7.0 đi du học Úc cuối năm 2026',
        ];

        $response = $this->actingAs($this->salesUser)->post(route('crm.customers.store'), $payload);

        $customer = CrmCustomer::where('phone', '0977 123 456')->first();
        $this->assertNotNull($customer);

        $response->assertRedirect(route('crm.customers.show', $customer->id));

        $this->assertDatabaseHas('crm_customers', [
            'id' => $customer->id,
            'name' => 'Hoàng Nhật Minh',
            'phone' => '0977 123 456',
            'email' => 'nhatminh.hoang@gmail.com',
            'gender' => 'Nam',
            'address' => 'P402 Tòa nhà Golden Land, Thanh Xuân, Hà Nội',
            'branch_id' => $this->branch->id,
            'course_interest' => 'IELTS Pro 6.5 - 7.5',
            'source' => 'Facebook Ads',
            'assigned_user_id' => $this->salesUser->id,
            'stage' => 'new',
            'deal_value' => 15000000,
            'notes' => 'Học viên mục tiêu 7.0 đi du học Úc cuối năm 2026',
        ]);

        $this->assertEquals('2004-11-20', $customer->dob?->format('Y-m-d'));
        $this->assertStringStartsWith('KH-', $customer->code);

        // Check auto-generated initial history record
        $this->assertDatabaseHas('crm_customer_histories', [
            'customer_id' => $customer->id,
            'user_id' => $this->salesUser->id,
            'type' => 'system',
        ]);
    }

    public function test_lead_creation_validations(): void
    {
        // 1. Missing required name & phone
        $response = $this->actingAs($this->salesUser)->post(route('crm.customers.store'), [
            'name' => '',
            'phone' => '',
            'email' => 'invalid-email',
            'deal_value' => -50000,
        ]);

        $response->assertSessionHasErrors(['name', 'phone', 'email', 'deal_value']);
    }

    public function test_can_view_lead_pipeline_and_filter_by_branch_and_stage(): void
    {
        $cust1 = CrmCustomer::create([
            'code' => 'KH-00001',
            'name' => 'Lê Bích Phương',
            'phone' => '0911222333',
            'stage' => 'consulting',
            'deal_value' => 12000000,
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->salesUser->id,
        ]);

        $cust2 = CrmCustomer::create([
            'code' => 'KH-00002',
            'name' => 'Đỗ Duy Mạnh',
            'phone' => '0944555666',
            'stage' => 'closing',
            'deal_value' => 18000000,
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->salesUser->id,
        ]);

        $responsePipeline = $this->actingAs($this->salesUser)->get(route('crm.pipeline'));
        $responsePipeline->assertOk();
        $responsePipeline->assertSee('Lê Bích Phương');
        $responsePipeline->assertSee('Đỗ Duy Mạnh');

        $responseFilter = $this->actingAs($this->salesUser)->get(route('crm.customers.index', [
            'search' => 'Bích Phương',
            'stage' => 'consulting',
            'branch_id' => $this->branch->id,
        ]));
        $responseFilter->assertOk();
        $responseFilter->assertSee('Lê Bích Phương');
        $responseFilter->assertDontSee('Đỗ Duy Mạnh');
    }

    // =========================================================================
    // b. Stage updates and care note history tracking
    // =========================================================================

    public function test_can_update_lead_details_and_track_stage_change_in_history(): void
    {
        $customer = CrmCustomer::create([
            'code' => 'KH-00100',
            'name' => 'Vũ Thuỳ Dung',
            'phone' => '0933999888',
            'stage' => 'new',
            'deal_value' => 10000000,
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->salesUser->id,
        ]);

        $response = $this->actingAs($this->salesUser)->put(route('crm.customers.update', $customer->id), [
            'name' => 'Vũ Thuỳ Dung (VIP Lead)',
            'phone' => '0933999888',
            'email' => 'thuydung.vu@gmail.com',
            'branch_id' => $this->branch->id,
            'source' => 'Hotline',
            'deal_value' => 12500000,
            'notes' => 'Hẹn ca test 14h chiều thứ 7 tuần này.',
        ]);

        $response->assertRedirect(route('crm.customers.show', $customer->id));

        $this->actingAs($this->salesUser)->post(route('crm.customers.stage', $customer->id), ['stage' => 'consulting']);
        $customer->refresh();
        $this->assertEquals('Vũ Thuỳ Dung (VIP Lead)', $customer->name);
        $this->assertEquals('consulting', $customer->stage);
        $this->assertEquals(12500000, $customer->deal_value);

        // Verify stage_change history record was created
        $this->assertDatabaseHas('crm_customer_histories', [
            'customer_id' => $customer->id,
            'user_id' => $this->salesUser->id,
            'type' => 'stage_change',
        ]);
    }

    public function test_can_schedule_placement_test_appointment_for_lead(): void
    {
        $test = PlacementTest::create([
            'code' => 'TEST-SCHED-01',
            'title' => 'Đề Test Chuẩn Hóa Diagnostic',
            'target_level' => 'A1 - B2',
            'duration_minutes' => 60,
            'questions_count' => 40,
            'is_active' => true,
        ]);

        $customer = CrmCustomer::create([
            'code' => 'KH-00150',
            'name' => 'Hoàng Nhật Minh',
            'phone' => '0911222333',
            'stage' => 'consulting',
            'assigned_user_id' => $this->salesUser->id,
        ]);

        $schedulePayload = [
            'appointment_date' => '2026-10-25',
            'appointment_time' => '15:30',
            'appointment_type' => 'online',
            'assigned_test_id' => $test->id,
            'examiner_id' => $this->examiner->id,
            'notes' => 'Học viên hẹn làm bài test online qua link portal.',
        ];

        $response = $this->actingAs($this->salesUser)->post(route('crm.customers.schedule-test', $customer->id), $schedulePayload);
        $response->assertRedirect();

        $customer->refresh();
        $this->assertEquals('test_scheduled', $customer->stage);
        $this->assertEquals('online', $customer->appointment_type);
        $this->assertEquals($test->id, $customer->assigned_test_id);
        $this->assertEquals($this->examiner->id, $customer->examiner_id);
        $this->assertEquals('2026-10-25 15:30:00', $customer->appointment_at->format('Y-m-d H:i:s'));

        // Verify history log for test scheduling
        $this->assertDatabaseHas('crm_customer_histories', [
            'customer_id' => $customer->id,
            'type' => 'test',
        ]);
    }

    public function test_can_add_care_notes_of_various_types(): void
    {
        $customer = CrmCustomer::create([
            'code' => 'KH-00200',
            'name' => 'Phan Anh Tuấn',
            'phone' => '0966888777',
            'stage' => 'consulting',
            'assigned_user_id' => $this->salesUser->id,
        ]);

        $types = ['call', 'message', 'meet', 'test', 'note'];

        foreach ($types as $type) {
            $noteContent = "Nhật ký tương tác loại {$type} với học viên.";

            $response = $this->actingAs($this->salesUser)->post(route('crm.customers.notes.store', $customer->id), [
                'type' => $type,
                'content' => $noteContent,
            ]);

            $response->assertRedirect(route('crm.customers.show', $customer->id));

            $this->assertDatabaseHas('crm_customer_histories', [
                'customer_id' => $customer->id,
                'user_id' => $this->salesUser->id,
                'type' => $type,
                'content' => $noteContent,
            ]);
        }
    }

    public function test_can_delete_lead_and_view_reports(): void
    {
        $customer = CrmCustomer::create([
            'code' => 'KH-00300',
            'name' => 'Tạ Quang Thắng',
            'phone' => '0922333444',
            'stage' => 'lost',
            'assigned_user_id' => $this->salesUser->id,
        ]);

        $responseDelete = $this->actingAs($this->salesUser)->delete(route('crm.customers.destroy', $customer->id));
        $responseDelete->assertForbidden();
        $this->assertNotSoftDeleted('crm_customers', ['id' => $customer->id]);

        $responseReports = $this->actingAs($this->salesUser)->get(route('crm.reports'));
        $responseReports->assertOk();
        $responseReports->assertViewHas('metricTotalLeads');
        $responseReports->assertViewHas('repsData');
        $responseReports->assertViewHas('funnelStages');
    }

    // =========================================================================
    // c. Closing Wizard interlinked flow (Full & Partial Payments)
    // =========================================================================

    public function test_closing_wizard_full_payment_flow(): void
    {
        $customer = CrmCustomer::create([
            'code' => 'KH-CW001',
            'name' => 'Ngô Bảo Châu',
            'phone' => '0901234567',
            'email' => 'baochau.ngo@gmail.com',
            'dob' => '2002-05-10',
            'gender' => 'Nam',
            'address' => 'Kim Mã, Ba Đình, Hà Nội',
            'branch_id' => $this->branch->id,
            'course_interest' => 'IELTS Pro 6.5 - 7.5',
            'test_score' => '5.5 Overall',
            'stage' => 'closing',
            'assigned_user_id' => $this->salesUser->id,
        ]);

        $promotion = Promotion::create([
            'code' => 'FULL2M', 'name' => 'Giảm 2 triệu', 'type' => 'fixed', 'value' => 2000000, 'is_active' => true,
        ]);

        $wizardPayload = [
            'customer_id' => $customer->id,
            'class_id' => $this->classModel->id,
            'course_name' => 'IELTS Pro 6.5 - 7.5',
            'base_tuition' => 15000000,
            'discount' => 2000000,
            'promotion_id' => $promotion->id,
            'paid_amount' => 13000000,
            'payment_method' => 'transfer',
            'bank_account_id' => $this->bankAccount->id,
        ];

        $response = $this->actingAs($this->salesUser)->post(route('crm.closing-wizard.store'), $wizardPayload);

        $response->assertRedirect(route('crm.customers.won'));

        // 1. Lead updated to 'won' and deal_value = final amount (13M)
        $customer->refresh();
        $this->assertEquals('won', $customer->stage);
        $this->assertEquals(13000000, $customer->deal_value);

        // 2. Student created with copied profile data and active class
        $student = Student::where('phone', '0901234567')->first();
        $this->assertNotNull($student);
        $this->assertStringStartsWith('HV-', $student->code);
        $this->assertEquals('Ngô Bảo Châu', $student->name);
        $this->assertEquals('baochau.ngo@gmail.com', $student->email);
        $this->assertEquals('2002-05-10', $student->dob?->format('Y-m-d'));
        $this->assertEquals('Nam', $student->gender);
        $this->assertEquals($this->branch->id, $student->branch_id);
        $this->assertEquals($this->classModel->id, $student->current_class_id);
        $this->assertEquals('studying', $student->status);

        // 3. ClassEnrollment created
        $this->assertDatabaseHas('class_enrollments', [
            'student_id' => $student->id,
            'class_id' => $this->classModel->id,
            'customer_id' => $customer->id,
            'curriculum_delivered' => false,
            'zalo_group_added' => false,
            'status' => 'pending',
        ]);

        // 4. StudentTuition stays unpaid until the receipt is approved
        $this->assertDatabaseHas('student_tuitions', [
            'student_id' => $student->id,
            'class_id' => $this->classModel->id,
            'branch_id' => $this->branch->id,
            'total_amount' => 15000000,
            'discount_amount' => 2000000,
            'final_amount' => 13000000,
            'paid_amount' => 0,
            'debt_amount' => 13000000,
            'status' => 'unpaid',
        ]);

        $tuition = StudentTuition::where('student_id', $student->id)->first();
        $this->assertNotNull($tuition);

        // 5. Receipt is pending; invoice is generated only after approval
        $receipt = TuitionReceipt::where('student_tuition_id', $tuition->id)->first();
        $this->assertNotNull($receipt);
        $this->assertEquals(13000000, $receipt->amount);
        $this->assertEquals('pending', $receipt->status);
        $this->assertNull($receipt->invoice_number);
        $this->assertNull($receipt->approver_id);
    }

    public function test_closing_wizard_partial_deposit_flow(): void
    {
        $customer = CrmCustomer::create([
            'code' => 'KH-CW002',
            'name' => 'Trịnh Thị Mai',
            'phone' => '0988666555',
            'stage' => 'closing',
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->salesUser->id,
        ]);

        $promotion = Promotion::create([
            'code' => 'PART1M', 'name' => 'Giảm 1 triệu', 'type' => 'fixed', 'value' => 1000000, 'is_active' => true,
        ]);

        // Base 16M, discount 1M, paid deposit 5M -> debt = 10M, status = partial
        $wizardPayload = [
            'customer_id' => $customer->id,
            'class_id' => $this->classModel->id,
            'course_name' => 'IELTS Pro 6.5 - 7.5',
            'base_tuition' => 16000000,
            'discount' => 1000000,
            'promotion_id' => $promotion->id,
            'paid_amount' => 5000000,
            'payment_method' => 'cash',
            'bank_account_id' => $this->bankAccount->id,
        ];

        $response = $this->actingAs($this->salesUser)->post(route('crm.closing-wizard.store'), $wizardPayload);
        $response->assertRedirect(route('crm.customers.won'));

        $customer->refresh();
        $this->assertEquals('won', $customer->stage);
        $this->assertEquals(14000000, $customer->deal_value);

        $student = Student::where('phone', '0988666555')->first();
        $this->assertNotNull($student);

        // Pending money is not counted as collected until approval
        $this->assertDatabaseHas('student_tuitions', [
            'student_id' => $student->id,
            'final_amount' => 14000000,
            'paid_amount' => 0,
            'debt_amount' => 14000000,
            'status' => 'unpaid',
        ]);
        $this->assertDatabaseHas('tuition_receipts', [
            'student_id' => $student->id,
            'amount' => 5000000,
            'status' => 'pending',
        ]);
    }

    public function test_closing_wizard_validation_failures(): void
    {
        $response = $this->actingAs($this->salesUser)->post(route('crm.closing-wizard.store'), [
            'customer_id' => 999999, // Non-existent
            'class_id' => 999999, // Non-existent
            'course_name' => '',
            'base_tuition' => -1000,
            'paid_amount' => -500,
            'payment_method' => '',
        ]);

        $response->assertSessionHasErrors([
            'customer_id',
            'class_id',
            'paid_amount',
            'payment_method',
        ]);
    }

    // =========================================================================
    // d. Regression: promotion, search, reports, wizard & bill edge cases
    // =========================================================================

    public function test_can_create_promotion_with_end_date_without_start_date(): void
    {
        $admin = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->post(route('crm.promotions.store'), [
            'name' => 'Ưu đãi cuối tháng',
            'type' => 'percent',
            'value' => 10,
            'ends_at' => now()->addMonth()->format('Y-m-d H:i:s'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $promotion = Promotion::where('name', 'Ưu đãi cuối tháng')->firstOrFail();
        $this->assertNotNull($promotion->ends_at);
        $this->assertNull($promotion->starts_at);
        $this->assertStringStartsWith('UD', $promotion->code);
    }

    public function test_customer_search_matches_normalized_phone(): void
    {
        CrmCustomer::create([
            'code' => 'KH-SEARCH-01',
            'name' => 'Nguyễn Văn Tìm Kiếm',
            'phone' => '0912345678',
            'phone_normalized' => '0912345678',
            'stage' => 'consulting',
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->salesUser->id,
        ]);

        // Gõ SĐT có khoảng trắng vẫn phải tìm được lead qua phone_normalized.
        $this->actingAs($this->salesUser)->get(route('crm.customers.index', ['search' => '0912 345']))
            ->assertOk()
            ->assertSee('Nguyễn Văn Tìm Kiếm');
    }

    public function test_reports_with_invalid_custom_dates_render_instead_of_erroring(): void
    {
        $this->actingAs($this->salesUser)->get(route('crm.reports', [
            'preset' => 'custom',
            'start_date' => 'not-a-date',
            'end_date' => 'also-bad',
        ]))->assertOk();
    }

    public function test_closing_wizard_redirects_instead_of_erroring_when_lead_not_ready(): void
    {
        $customer = CrmCustomer::create([
            'code' => 'KH-NOTREADY-01',
            'name' => 'Lead Chưa Đủ Điều Kiện Chốt',
            'phone' => '0977888999',
            'stage' => 'consulting',
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->salesUser->id,
        ]);

        $this->actingAs($this->salesUser)
            ->get(route('crm.closing-wizard', ['customer_id' => $customer->id]))
            ->assertRedirect(route('crm.pipeline'));
    }

    public function test_tuition_bill_with_missing_bank_account_renders_with_warning(): void
    {
        $customer = CrmCustomer::create([
            'code' => 'KH-NOBANK-01',
            'name' => 'Học Viên Không Ngân Hàng',
            'phone' => '0966111222',
            'stage' => 'closing',
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->salesUser->id,
        ]);

        $this->actingAs($this->salesUser)->post(route('crm.closing-wizard.store'), [
            'customer_id' => $customer->id,
            'class_id' => $this->classModel->id,
            'paid_amount' => 5000000,
            'payment_method' => 'cash',
        ])->assertRedirect(route('crm.customers.won'));

        // Tắt toàn bộ tài khoản ngân hàng: bill vẫn phải hiển thị kèm cảnh báo, không lỗi.
        BankAccount::query()->update(['is_active' => false]);
        $tuition = StudentTuition::where('student_id', $customer->fresh()->converted_student_id)->firstOrFail();

        $this->actingAs($this->salesUser)->get(route('crm.tuition-bill', $tuition->id))
            ->assertOk()
            ->assertSee('Chưa cấu hình tài khoản nhận tiền')
            ->assertDontSee('img.vietqr.io', false);
    }
}
