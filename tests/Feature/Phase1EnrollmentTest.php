<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\PlacementTest;
use App\Models\PlacementTestSubmission;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Phase 1 CRM → vào lớp: lớp sắp khai giảng + ngưỡng khai giảng trong Chốt & Xếp lớp,
 * "Xác nhận chính thức", đề test / bài làm (xóa đề, phạm vi chi nhánh), nhập khách từ Excel.
 */
class Phase1EnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    private User $admin;

    private User $manager;

    private User $academic;

    private User $sales;

    private Course $course;

    private BankAccount $bank;

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

        $this->course = Course::create(['code' => 'FAM1', 'name' => 'Starters FAM 1', 'tuition_fee' => 10000000, 'total_lessons' => 48, 'is_active' => true]);
        $this->bank = BankAccount::create([
            'bank_code' => 'VCB', 'bank_name' => 'Vietcombank', 'account_number' => '123456',
            'account_holder' => 'MENGLISH', 'branch_id' => $this->branch->id, 'is_default_vietqr' => true, 'is_active' => true,
        ]);
    }

    // ── 8 + 10. Lớp sắp khai giảng, sĩ số, ngưỡng khai giảng ─────────────

    public function test_closing_wizard_offers_upcoming_classes_with_opening_threshold(): void
    {
        $upcoming = $this->makeClass('FAM1-UP', 'upcoming', ['start_date' => now()->addDays(10), 'max_capacity' => 10]);
        $this->assertSame(6, (int) $upcoming->fresh()->min_students);
        $started = $this->makeClass('FAM1-OLD', 'upcoming', ['start_date' => now()->subDays(3)]);
        $active = $this->makeClass('FAM1-ACT', 'active', ['max_capacity' => 3]);
        $this->enrollDummy($upcoming, 2);
        $this->enrollDummy($active, 1);
        $lead = $this->lead('consulting');

        $response = $this->actingAs($this->sales)->get(route('crm.closing-wizard', ['customer_id' => $lead->id]))->assertOk();
        $codes = $response->viewData('classes')->pluck('code')->all();
        $this->assertContains('FAM1-UP', $codes);
        $this->assertContains('FAM1-ACT', $codes);
        $this->assertNotContains('FAM1-OLD', $codes);
        $response->assertSee('Cần thêm 4 học viên để khai giảng')
            ->assertSee('Còn 8 chỗ')
            ->assertSee('Còn 2 chỗ')
            ->assertSee('Sắp khai giảng');

        // Chốt vào lớp sắp khai giảng được chấp nhận; lớp đã qua ngày khai giảng mà còn "upcoming" thì không.
        $this->actingAs($this->sales)->post(route('crm.closing-wizard.store'), $this->closingPayload($lead, $upcoming))
            ->assertRedirect(route('crm.customers.won'))->assertSessionHasNoErrors();
        $this->assertSame('won', $lead->fresh()->stage);
        $this->assertDatabaseHas('class_enrollments', ['class_id' => $upcoming->id, 'customer_id' => $lead->id, 'status' => 'pending']);

        $other = $this->lead('consulting');
        $this->actingAs($this->sales)->post(route('crm.closing-wizard.store'), $this->closingPayload($other, $started))
            ->assertSessionHasErrors('class_id');
    }

    public function test_closing_into_full_upcoming_class_is_rejected(): void
    {
        $full = $this->makeClass('FAM1-FULL', 'upcoming', ['start_date' => now()->addWeek(), 'max_capacity' => 2]);
        $this->enrollDummy($full, 2);
        $lead = $this->lead('consulting');

        $this->assertNotContains('FAM1-FULL', $this->actingAs($this->sales)->get(route('crm.closing-wizard'))->viewData('classes')->pluck('code'));
        $this->actingAs($this->sales)->post(route('crm.closing-wizard.store'), $this->closingPayload($lead, $full))
            ->assertSessionHasErrors('class_id');
        $this->assertSame('consulting', $lead->fresh()->stage);
    }

    // ── 9. Xác nhận chính thức ────────────────────────────────────────────

    public function test_official_confirmation_requires_full_paperwork_and_activates_student(): void
    {
        $class = $this->makeClass('FAM1-ACT', 'active', ['start_date' => now()->subWeek()]);
        $lead = $this->lead('consulting');
        $this->actingAs($this->sales)->post(route('crm.closing-wizard.store'), $this->closingPayload($lead, $class))->assertSessionHasNoErrors();
        $enrollment = ClassEnrollment::where('customer_id', $lead->id)->firstOrFail();
        $student = Student::findOrFail($lead->fresh()->converted_student_id);

        $this->actingAs($this->academic)->get(route('crm.customers.won'))->assertOk()->assertSee('Xác nhận chính thức');
        $this->actingAs($this->academic)->get(route('crm.confirmations'))->assertOk()->assertSee($student->name)->assertSee('Đã gửi tài khoản học viên');
        $this->actingAs($this->sales)->get(route('crm.confirmations'))->assertForbidden();

        // Thiếu hồ sơ → không xác nhận được, nhưng lưu được tiến độ.
        $this->actingAs($this->academic)->post(route('crm.enrollments.confirm', $enrollment), [
            'account_sent' => 1, 'zalo_group_added' => 0, 'curriculum_delivered' => 1, 'action' => 'confirm',
        ])->assertSessionHasErrors('enrollment');
        $this->assertNull($enrollment->fresh()->confirmed_at);

        $this->actingAs($this->academic)->post(route('crm.enrollments.confirm', $enrollment), [
            'account_sent' => 1, 'zalo_group_added' => 0, 'curriculum_delivered' => 1, 'action' => 'save',
        ])->assertSessionHasNoErrors();
        $this->assertTrue($enrollment->fresh()->account_sent);
        $this->assertNull($enrollment->fresh()->confirmed_at);

        $this->actingAs($this->academic)->post(route('crm.enrollments.confirm', $enrollment), [
            'account_sent' => 1, 'zalo_group_added' => 1, 'curriculum_delivered' => 1, 'action' => 'confirm',
        ])->assertSessionHasNoErrors();
        $enrollment->refresh();
        $this->assertNotNull($enrollment->confirmed_at);
        $this->assertSame($this->academic->id, $enrollment->confirmed_by);
        $this->assertSame('completed', $enrollment->status);
        $this->assertSame('studying', $student->fresh()->status);
        $this->assertDatabaseHas('crm_customer_histories', ['customer_id' => $lead->id, 'type' => 'system']);

        $this->actingAs($this->academic)->get(route('crm.confirmations'))->assertDontSee($student->code);
        $this->actingAs($this->academic)->get(route('crm.confirmations', ['status' => 'confirmed']))->assertSee($student->code);
        $this->actingAs($this->academic)->get(route('crm.customers.won'))->assertSee('Đã là học viên');

        // Đã xác nhận thì không xác nhận lại.
        $this->actingAs($this->academic)->post(route('crm.enrollments.confirm', $enrollment), [
            'account_sent' => 1, 'zalo_group_added' => 1, 'curriculum_delivered' => 1, 'action' => 'confirm',
        ])->assertSessionHasErrors('enrollment');
    }

    public function test_confirmation_for_upcoming_class_keeps_waiting_status_and_is_branch_scoped(): void
    {
        $class = $this->makeClass('FAM1-UP', 'upcoming', ['start_date' => now()->addDays(5)]);
        $lead = $this->lead('consulting');
        $this->actingAs($this->sales)->post(route('crm.closing-wizard.store'), $this->closingPayload($lead, $class))->assertSessionHasNoErrors();
        $enrollment = ClassEnrollment::where('customer_id', $lead->id)->firstOrFail();

        $foreignAcademic = $this->userWithRole('academic_staff', $this->otherBranch);
        $this->actingAs($foreignAcademic)->get(route('crm.confirmations'))->assertOk()->assertDontSee($lead->name);
        $this->actingAs($foreignAcademic)->post(route('crm.enrollments.confirm', $enrollment), [
            'account_sent' => 1, 'zalo_group_added' => 1, 'curriculum_delivered' => 1, 'action' => 'confirm',
        ])->assertNotFound();

        $this->actingAs($this->manager)->post(route('crm.enrollments.confirm', $enrollment), [
            'account_sent' => 1, 'zalo_group_added' => 1, 'curriculum_delivered' => 1, 'action' => 'confirm',
        ])->assertSessionHasNoErrors();
        $this->assertNotNull($enrollment->fresh()->confirmed_at);
        $this->assertSame(Student::INITIAL_STATUS, Student::find($lead->fresh()->converted_student_id)->status);
    }

    // ── 6–7. Đề test đầu vào ──────────────────────────────────────────────

    public function test_placement_test_with_submissions_cannot_be_deleted(): void
    {
        $test = PlacementTest::create(['code' => 'CUSTOM-1', 'title' => 'Đề riêng', 'is_active' => true]);
        $submission = PlacementTestSubmission::create(['placement_test_id' => $test->id, 'candidate_name' => 'Thí sinh', 'candidate_phone' => '0912345678', 'status' => 'pending']);

        $this->actingAs($this->admin)->delete(route('placement-tests.destroy', $test->id))
            ->assertRedirect(route('placement-tests.index'))->assertSessionHas('error');
        $this->assertDatabaseHas('placement_tests', ['id' => $test->id]);
        $this->assertDatabaseHas('placement_test_submissions', ['id' => $submission->id]);

        $empty = PlacementTest::create(['code' => 'CUSTOM-2', 'title' => 'Đề trống', 'is_active' => true]);
        $this->actingAs($this->admin)->delete(route('placement-tests.destroy', $empty->id))->assertSessionHas('status');
        $this->assertDatabaseMissing('placement_tests', ['id' => $empty->id]);
    }

    public function test_placement_results_follow_crm_branch_scope(): void
    {
        $test = PlacementTest::create(['code' => 'SCOPE-1', 'title' => 'Đề phạm vi', 'is_active' => true]);
        $foreignLead = $this->lead('testing', $this->otherBranch);
        $foreignSubmission = PlacementTestSubmission::create([
            'placement_test_id' => $test->id, 'customer_id' => $foreignLead->id, 'candidate_name' => 'Khách CS B',
            'candidate_phone' => $foreignLead->phone, 'listening_score' => 5, 'reading_score' => 5, 'status' => 'pending',
        ]);
        $ownLead = $this->lead('testing');
        $ownSubmission = PlacementTestSubmission::create([
            'placement_test_id' => $test->id, 'customer_id' => $ownLead->id, 'candidate_name' => 'Khách CS A',
            'candidate_phone' => $ownLead->phone, 'status' => 'pending',
        ]);
        $walkIn = PlacementTestSubmission::create(['placement_test_id' => $test->id, 'candidate_name' => 'Khách vãng lai', 'candidate_phone' => '0900000001', 'status' => 'pending']);
        $grade = ['grade_group' => 'khoi_2_3', 'listening_score' => 6, 'reading_writing_score' => 6, 'speaking_score' => 6];

        foreach ([$this->manager, $this->academic] as $user) {
            $this->actingAs($user)->get(route('placement-tests.results.show', $foreignSubmission->id))->assertNotFound();
            $this->actingAs($user)->post(route('placement-tests.results.update', $foreignSubmission->id), $grade)->assertNotFound();
            $this->actingAs($user)->get(route('placement-tests.results.show', $ownSubmission->id))->assertOk();
            $this->actingAs($user)->get(route('placement-tests.results.show', $walkIn->id))->assertOk();
        }
        $this->assertSame('pending', $foreignSubmission->fresh()->status);

        $this->actingAs($this->admin)->get(route('placement-tests.results.show', $foreignSubmission->id))->assertOk();
        $this->actingAs($this->academic)->get(route('placement-tests.index'))->assertOk()
            ->assertDontSee('Khách CS B')->assertSee('Khách CS A');
    }

    // ── 15. Nhập khách từ Excel ───────────────────────────────────────────

    public function test_customer_excel_import_previews_row_errors_then_imports_valid_rows(): void
    {
        $existing = $this->lead('consulting');
        $csv = implode("\n", [
            'Họ tên,Số điện thoại,Tên phụ huynh,SĐT phụ huynh,Email,Nguồn,Khóa học quan tâm',
            'Nguyễn An,0912 345 678,Mẹ An,0987654321,an@example.com,Facebook Ads,Starters',
            'Trần Bình,12345,,,,,',
            'Lê Chi,+84912345678,,,,,',
            ',0934567890,,,,,',
            'Phạm Dũng,'.$existing->phone.',,,,,',
            'Hoàng Em,0978123456,,,sai-email,,',
            'Vũ Giang,0978999888,,,,,Movers',
        ]);
        $file = UploadedFile::fake()->createWithContent('khach.csv', "\xEF\xBB\xBF".$csv);

        $this->actingAs($this->manager)->get(route('crm.import'))->assertOk()->assertSee('Nhập khách hàng loạt từ Excel');
        $this->actingAs($this->manager)->post(route('crm.import.preview'), [
            'file' => $file, 'branch_id' => $this->branch->id, 'assigned_user_id' => $this->sales->id, 'default_source' => 'Hội thảo',
        ])->assertRedirect(route('crm.import'))->assertSessionHasNoErrors();

        $preview = $this->actingAs($this->manager)->get(route('crm.import'))->assertOk()
            ->assertSee('SĐT sai định dạng')
            ->assertSee('Trùng SĐT với dòng 2 trong file')
            ->assertSee('Thiếu họ tên')
            ->assertSee('SĐT đã có trong CRM')
            ->assertSee('Email sai định dạng')
            ->assertSee('Nhập 2 khách hợp lệ');
        $this->assertNotNull($preview);

        $this->actingAs($this->manager)->post(route('crm.import.store'))->assertRedirect(route('crm.customers.index'));
        $an = CrmCustomer::where('name', 'Nguyễn An')->firstOrFail();
        $this->assertSame('0912345678', $an->phone_normalized);
        $this->assertSame($this->branch->id, $an->branch_id);
        $this->assertSame($this->sales->id, $an->assigned_user_id);
        $this->assertSame('0987654321', $an->parent_phone);
        $this->assertSame('new', $an->stage);
        $giang = CrmCustomer::where('name', 'Vũ Giang')->firstOrFail();
        $this->assertSame('Hội thảo', $giang->source);
        $this->assertSame(3, CrmCustomer::count());
        $this->assertDatabaseHas('crm_customer_histories', ['customer_id' => $an->id, 'type' => 'system']);
        $this->assertNull(session('crm_customer_import'));
    }

    public function test_import_rejects_foreign_branch_and_sales_import_to_self(): void
    {
        $file = UploadedFile::fake()->createWithContent('khach.csv', "Họ tên,SĐT\nKhách Sale,0911000111\n");

        $this->actingAs($this->manager)->post(route('crm.import.preview'), ['file' => $file, 'branch_id' => $this->otherBranch->id])
            ->assertSessionHasErrors('branch_id');

        $this->actingAs($this->sales)->post(route('crm.import.preview'), [
            'file' => UploadedFile::fake()->createWithContent('khach.csv', "Họ tên,SĐT\nKhách Sale,0911000111\n"),
            'branch_id' => $this->branch->id, 'assigned_user_id' => $this->manager->id,
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->sales)->post(route('crm.import.store'))->assertRedirect();
        $this->assertSame($this->sales->id, CrmCustomer::where('name', 'Khách Sale')->value('assigned_user_id'));

        $this->actingAs($this->manager)->post(route('crm.import.preview'), [
            'file' => UploadedFile::fake()->createWithContent('khach.csv', "Cột A,Cột B\nx,y\n"), 'branch_id' => $this->branch->id,
        ])->assertSessionHasErrors('file');

        $this->actingAs($this->manager)->get(route('crm.import.template'))->assertOk()->assertDownload('mau-nhap-khach-hang.xlsx');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function makeClass(string $code, string $status, array $attributes = []): ClassModel
    {
        return ClassModel::create(array_merge([
            'code' => $code, 'name' => 'Lớp '.$code, 'course_id' => $this->course->id, 'branch_id' => $this->branch->id,
            'max_capacity' => 10, 'status' => $status, 'tuition_fee' => 10000000,
        ], $attributes));
    }

    private function enrollDummy(ClassModel $class, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $student = Student::create(['code' => 'HV-D'.$class->id.'-'.$i, 'name' => 'HV dummy', 'phone' => '0900000000', 'branch_id' => $this->branch->id, 'status' => 'studying']);
            ClassEnrollment::create(['student_id' => $student->id, 'class_id' => $class->id, 'status' => 'completed']);
        }
    }

    private function closingPayload(CrmCustomer $lead, ClassModel $class): array
    {
        return [
            'customer_id' => $lead->id,
            'class_id' => $class->id,
            'fee_paid_at_closing' => 1,
            'paid_amount' => 10000000,
            'payment_method' => 'transfer',
            'bank_account_id' => $this->bank->id,
        ];
    }

    private function lead(string $stage, ?Branch $branch = null): CrmCustomer
    {
        $phone = '09'.random_int(10000000, 99999999);

        return CrmCustomer::create([
            'code' => CrmCustomer::generateCode(),
            'name' => 'Lead '.$stage.' '.random_int(100, 999),
            'phone' => $phone,
            'phone_normalized' => $phone,
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
