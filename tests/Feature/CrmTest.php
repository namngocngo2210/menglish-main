<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\PlacementTest;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_can_create_new_customer_and_save_to_database(): void
    {
        $user = $this->admin();
        $branch = Branch::create(['name' => 'Cơ sở Cầu Giấy', 'code' => 'CG', 'address' => 'Hà Nội', 'phone' => '0900000000', 'is_active' => true]);

        $response = $this->actingAs($user)->post('/crm/customers', [
            'name' => 'Nguyễn Minh Anh',
            'phone' => '0988 777 666',
            'email' => 'minhanh.nguyen@gmail.com',
            'dob' => '2003-08-15',
            'gender' => 'Nữ',
            'address' => 'Số 15 Cầu Giấy, Hà Nội',
            'branch_id' => $branch->id,
            'course_interest' => 'IELTS 6.5 Intensive',
            'source' => 'Facebook Ads',
            'assigned_user_id' => $user->id,
            'deal_value' => 14000000,
            'notes' => 'Cần thi gấp IELTS',
        ]);

        $this->assertDatabaseHas('crm_customers', [
            'name' => 'Nguyễn Minh Anh',
            'phone' => '0988 777 666',
            'email' => 'minhanh.nguyen@gmail.com',
            'course_interest' => 'IELTS 6.5 Intensive',
        ]);

        $customer = CrmCustomer::where('phone', '0988 777 666')->first();
        $this->assertNotNull($customer);
        $response->assertRedirect(route('crm.customers.show', $customer->id));

        // Kiểm tra history log được tạo tự động
        $this->assertDatabaseHas('crm_customer_histories', [
            'customer_id' => $customer->id,
        ]);
    }

    public function test_can_update_customer_in_database(): void
    {
        $user = $this->admin();
        $branch = Branch::create(['name' => 'Cơ sở Test', 'code' => 'TST', 'is_active' => true]);
        $customer = CrmCustomer::create([
            'code' => 'KH-99999',
            'name' => 'Trần Văn Cường',
            'phone' => '0912 345 678',
            'stage' => 'new',
            'deal_value' => 10000000,
        ]);

        $response = $this->actingAs($user)->put("/crm/customers/{$customer->id}", [
            'name' => 'Trần Văn Cường (VIP)',
            'phone' => '0912 345 678',
            'stage' => 'consulting',
            'deal_value' => 12500000,
            'branch_id' => $branch->id,
            'source' => 'Hotline',
        ]);

        $response->assertRedirect(route('crm.customers.show', $customer->id));
        $this->actingAs($user)->post(route('crm.customers.stage', $customer->id), ['stage' => 'consulting']);

        $this->assertDatabaseHas('crm_customers', [
            'id' => $customer->id,
            'name' => 'Trần Văn Cường (VIP)',
            'stage' => 'consulting',
        ]);
    }

    public function test_can_add_care_note_to_customer(): void
    {
        $user = $this->admin();
        $customer = CrmCustomer::create([
            'code' => 'KH-88888',
            'name' => 'Lê Thị Thuỷ',
            'phone' => '0945 111 222',
            'stage' => 'new',
        ]);

        $response = $this->actingAs($user)->post("/crm/customers/{$customer->id}/notes", [
            'type' => 'call',
            'content' => 'Đã gọi điện tư vấn 20 phút, học viên hẹn tuần sau test đầu vào.',
        ]);

        $response->assertRedirect(route('crm.customers.show', $customer->id));

        $this->assertDatabaseHas('crm_customer_histories', [
            'customer_id' => $customer->id,
            'type' => 'call',
            'content' => 'Đã gọi điện tư vấn 20 phút, học viên hẹn tuần sau test đầu vào.',
        ]);
    }

    public function test_closing_wizard_interconnection(): void
    {
        $user = $this->admin();
        $customer = CrmCustomer::create([
            'code' => 'KH-CW001',
            'name' => 'Hoàng Mai Phương',
            'phone' => '0933 444 555',
            'stage' => 'result_sent',
        ]);
        $course = Course::create([
            'code' => 'COURSE-CW', 'name' => 'IELTS 6.5 Intensive', 'tuition_fee' => 14000000, 'is_active' => true,
        ]);
        $class = ClassModel::create([
            'code' => 'IE-CW',
            'name' => 'Lớp IELTS Closing Test',
            'course_id' => $course->id,
            'status' => 'active',
        ]);
        $bank = BankAccount::create([
            'bank_code' => 'VCB', 'bank_name' => 'Vietcombank', 'account_number' => '123456',
            'account_holder' => 'MENGLISH', 'is_active' => true, 'is_default_vietqr' => true,
        ]);

        $response = $this->actingAs($user)->post('/crm/closing-wizard', [
            'customer_id' => $customer->id,
            'class_id' => $class->id,
            'course_name' => 'IELTS 6.5 Intensive',
            'base_tuition' => 14000000,
            'paid_amount' => 14000000,
            'payment_method' => 'transfer',
            'bank_account_id' => $bank->id,
        ]);

        $response->assertRedirect(route('crm.customers.won'));

        // 1. Customer stage is won
        $customer->refresh();
        $this->assertEquals('won', $customer->stage);
        $this->assertEquals(14000000, $customer->deal_value);

        // 2. Student created
        $this->assertDatabaseHas('students', [
            'name' => 'Hoàng Mai Phương',
            'phone' => '0933 444 555',
            'current_class_id' => $class->id,
        ]);

        // 3. ClassEnrollment created
        $this->assertDatabaseHas('class_enrollments', [
            'class_id' => $class->id,
            'customer_id' => $customer->id,
            'curriculum_delivered' => false,
            'zalo_group_added' => false,
            'status' => 'pending',
        ]);

        // 4. Tuition remains unpaid until accounting approves the receipt
        $this->assertDatabaseHas('student_tuitions', [
            'class_id' => $class->id,
            'final_amount' => 14000000,
            'paid_amount' => 0,
            'debt_amount' => 14000000,
            'status' => 'unpaid',
        ]);

        // 5. Sales submits a receipt; accounting/admin must approve it
        $this->assertDatabaseHas('tuition_receipts', [
            'amount' => 14000000,
            'status' => 'pending',
            'approver_id' => null,
            'invoice_number' => null,
        ]);
    }

    public function test_can_delete_customer_from_database(): void
    {
        $user = $this->admin();
        $customer = CrmCustomer::create([
            'code' => 'KH-DELETE-01',
            'name' => 'Nguyễn Cần Xóa',
            'phone' => '0999 111 222',
            'stage' => 'new',
        ]);

        $response = $this->actingAs($user)->delete(route('crm.customers.destroy', $customer->id));

        $response->assertRedirect(route('crm.customers.index'));
        $this->assertSoftDeleted('crm_customers', [
            'id' => $customer->id,
        ]);
    }

    public function test_can_save_and_view_placement_test_score_in_crm_customer_page(): void
    {
        $user = $this->admin();
        $test = PlacementTest::create([
            'code' => 'TEST-IE-AUTO',
            'title' => 'Bài Test Đầu Vào Chuẩn IELTS',
            'duration_minutes' => 45,
            'is_active' => true,
        ]);

        $customer = CrmCustomer::create([
            'code' => 'KH-TEST-009',
            'name' => 'Micheal Owen',
            'phone' => '0832575905',
            'stage' => 'test_scheduled',
            'assigned_test_id' => $test->id,
        ]);

        // 1. Submit test score directly from CRM
        $response = $this->actingAs($user)->post(route('crm.customers.save-test-score', $customer->id), [
            'grade_group' => 'khac',
            'listening_score' => 6.5,
            'reading_writing_score' => 6.0,
            'speaking_score' => 7.0,
            'chosen_class' => 'IELTS 6.5 Intensive',
            'teacher_comments' => 'Phát âm chuẩn và phản xạ xuất sắc.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('placement_test_submissions', [
            'customer_id' => $customer->id,
            'grade_group' => 'khac',
            'total_score' => 19.5,
            'chosen_class' => 'IELTS 6.5 Intensive',
            'cefr_level' => null,
            'speaking_score' => 7.0,
        ]);

        $customer->refresh();
        $this->assertEquals('19.5/30 · IELTS 6.5 Intensive', $customer->test_score);
        $this->assertEquals('tested', $customer->stage);

        // 2. View customer page
        $viewResponse = $this->actingAs($user)->get(route('crm.customers.show', $customer->id));
        $viewResponse->assertOk();
        $viewResponse->assertSee('Đã làm bài test (19.5/30 · IELTS 6.5 Intensive)');
        $viewResponse->assertSee('Scorecard');
    }
}
