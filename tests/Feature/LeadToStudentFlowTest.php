<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\User;
use App\Services\Crm\WaitingLeadPlacement;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Luồng Lead → học viên (kiểm tra 27/09/2026): xếp lớp từ màn Học viên đồng bộ CRM, không ghi danh trùng,
 * học viên tự sang "Đang học" khi lớp khai giảng, lead đã liên hệ không bị cảnh báo >24h, cấp lại mật khẩu học viên.
 */
class LeadToStudentFlowTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $academic;

    private User $sales;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CSA', 'is_active' => true]);
        $this->academic = $this->userWithRole('academic_staff');
        $this->sales = $this->userWithRole('sales_consultant');
        $this->course = Course::create(['code' => 'FAM1', 'name' => 'Starters FAM 1', 'tuition_fee' => 10000000, 'total_lessons' => 48, 'is_active' => true]);
        BankAccount::create([
            'bank_code' => 'VCB', 'bank_name' => 'Vietcombank', 'account_number' => '123456',
            'account_holder' => 'MENGLISH', 'branch_id' => $this->branch->id, 'is_active' => true,
        ]);
    }

    public function test_linking_class_from_student_screen_places_waiting_lead(): void
    {
        [$lead, $student] = $this->closeWithoutClass();
        $class = $this->makeClass('F1A');

        $this->actingAs($this->academic)->post(route('students.link-class', $student->id), ['class_id' => $class->id])
            ->assertSessionHasNoErrors();

        $this->assertSame('won', $lead->fresh()->stage);
        $this->assertSame($class->id, StudentTuition::where('student_id', $student->id)->value('class_id'));
        $this->assertDatabaseHas('class_enrollments', ['student_id' => $student->id, 'class_id' => $class->id, 'customer_id' => $lead->id]);
        $this->assertNotContains($lead->id, $this->actingAs($this->academic)->get(route('crm.waiting-list'))->viewData('waitingLeads')->pluck('id'));

        // Gán lớp CRM lần nữa không tạo lượt ghi danh trùng.
        $this->actingAs($this->academic)->post(route('crm.customers.assign-class', $lead->id), ['class_id' => $class->id])
            ->assertSessionHasErrors('class_id');
        $this->assertSame(1, ClassEnrollment::where('student_id', $student->id)->count());
    }

    public function test_enrolling_from_student_screen_requires_the_closed_course(): void
    {
        [$lead, $student] = $this->closeWithoutClass();
        $otherCourse = Course::create(['code' => 'KET', 'name' => 'KET', 'tuition_fee' => 12000000, 'total_lessons' => 48, 'is_active' => true]);
        $class = $this->makeClass('KET1', ['course_id' => $otherCourse->id]);

        $this->actingAs($this->academic)->post(route('students.enrollments.store'), ['student_id' => $student->id, 'class_id' => $class->id])
            ->assertSessionHasErrors('class_id');
        $this->assertSame('waiting_class', $lead->fresh()->stage);
        $this->assertSame(0, ClassEnrollment::where('student_id', $student->id)->count());
    }

    public function test_enrollment_screen_offers_only_classes_of_the_closed_branch(): void
    {
        [$lead, $student] = $this->closeWithoutClass();
        $otherBranch = Branch::create(['name' => 'Cơ sở B', 'code' => 'CSB', 'is_active' => true]);
        $this->academic->branches()->attach($otherBranch->id);
        $this->makeClass('F1A');
        $wrongBranch = $this->makeClass('F1B', ['branch_id' => $otherBranch->id]);

        $rules = $this->actingAs($this->academic)->get(route('students.enrollments', ['student_id' => $student->id]))
            ->assertOk()->viewData('placementRules');
        $this->assertSame($this->branch->id, (int) $rules[$student->id]['branch_id']);
        $this->assertSame($this->course->id, (int) $rules[$student->id]['course_id']);

        $this->actingAs($this->academic)->post(route('students.enrollments.store'), ['student_id' => $student->id, 'class_id' => $wrongBranch->id])
            ->assertSessionHasErrors('class_id');
        $this->assertSame('waiting_class', $lead->fresh()->stage);
        $this->assertSame(0, ClassEnrollment::where('student_id', $student->id)->count());
    }

    public function test_placement_requires_the_closed_branch(): void
    {
        [$lead] = $this->closeWithoutClass();
        $otherBranch = Branch::create(['name' => 'Cơ sở B', 'code' => 'CSB', 'is_active' => true]);
        $placement = app(WaitingLeadPlacement::class);

        $placement->assertMatchesClosedBranch($lead, $this->makeClass('F1A'));

        try {
            $placement->assertMatchesClosedBranch($lead, $this->makeClass('F1B', ['branch_id' => $otherBranch->id]));
            $this->fail('Lớp khác chi nhánh đã chốt phải bị chặn.');
        } catch (ValidationException $e) {
            $this->assertSame('Lớp phải thuộc chi nhánh đã chốt (Cơ sở A).', $e->errors()['class_id'][0]);
        }
    }

    public function test_crm_assign_rejects_class_the_student_is_already_in(): void
    {
        [$lead, $student] = $this->closeWithoutClass();
        $class = $this->makeClass('F1A');
        ClassEnrollment::create(['student_id' => $student->id, 'class_id' => $class->id, 'status' => 'pending']);

        $this->actingAs($this->academic)->post(route('crm.customers.assign-class', $lead->id), ['class_id' => $class->id])
            ->assertSessionHasErrors('class_id');
        $this->assertSame('waiting_class', $lead->fresh()->stage);
    }

    public function test_crm_assign_rejects_upcoming_class_past_its_start_date(): void
    {
        [$lead] = $this->closeWithoutClass();
        $stale = $this->makeClass('F1OLD', ['status' => 'upcoming', 'start_date' => today()->subDays(3)]);

        $this->actingAs($this->academic)->post(route('crm.customers.assign-class', $lead->id), ['class_id' => $stale->id])
            ->assertSessionHasErrors('class_id');
    }

    public function test_confirmed_student_starts_studying_when_class_opens(): void
    {
        $class = $this->makeClass('F1A', ['start_date' => today()->addWeek()]);
        $lead = $this->lead('consulting');
        $this->actingAs($this->sales)->post(route('crm.closing-wizard.store'), ['customer_id' => $lead->id, 'class_id' => $class->id, 'fee_paid_at_closing' => 0])
            ->assertSessionHasNoErrors();
        $enrollment = ClassEnrollment::where('customer_id', $lead->id)->firstOrFail();

        $this->actingAs($this->academic)->post(route('crm.enrollments.confirm', $enrollment), [
            'account_sent' => 1, 'zalo_group_added' => 1, 'curriculum_delivered' => 1, 'action' => 'confirm',
        ])->assertSessionHasNoErrors();
        $this->assertSame('waiting_start', $enrollment->student->fresh()->status);

        $this->artisan('students:start-studying')->assertSuccessful();
        $this->assertSame('waiting_start', $enrollment->student->fresh()->status);

        $this->travelTo(today()->addWeek());
        $this->artisan('students:start-studying')->assertSuccessful();
        $this->assertSame('studying', $enrollment->student->fresh()->status);
    }

    public function test_unconfirmed_enrollment_does_not_start_studying(): void
    {
        $class = $this->makeClass('F1A', ['start_date' => today()->subDay()]);
        $student = Student::create(['code' => 'HV-P1', 'name' => 'HV chờ', 'branch_id' => $this->branch->id, 'status' => 'waiting_start', 'phone' => '0900000001']);
        ClassEnrollment::create(['student_id' => $student->id, 'class_id' => $class->id, 'status' => 'pending']);

        $this->artisan('students:start-studying')->assertSuccessful();
        $this->assertSame('waiting_start', $student->fresh()->status);
    }

    public function test_completing_handoff_on_student_screen_starts_studying_in_started_class(): void
    {
        $class = $this->makeClass('F1A', ['start_date' => today()->subDay()]);
        $student = Student::create(['code' => 'HV-H1', 'name' => 'HV bàn giao', 'branch_id' => $this->branch->id, 'status' => 'waiting_start', 'phone' => '0900000001']);
        $enrollment = ClassEnrollment::create(['student_id' => $student->id, 'class_id' => $class->id, 'status' => 'pending']);

        $this->actingAs($this->academic)->put(route('students.enrollments.update', $enrollment->id), ['curriculum_delivered' => 1, 'zalo_group_added' => 1])
            ->assertSessionHasNoErrors();

        $this->assertSame('studying', $student->fresh()->status);
    }

    public function test_contacted_new_lead_is_not_flagged_as_uncontacted(): void
    {
        $contacted = $this->lead('new');
        $silent = $this->lead('new');
        CrmCustomer::whereKey([$contacted->id, $silent->id])->update(['created_at' => now()->subHours(30)]);

        $this->actingAs($this->sales)->post(route('crm.customers.notes.store', $contacted->id), ['type' => 'call', 'content' => 'Gọi tư vấn lần 1'])
            ->assertRedirect();

        $this->assertSame('new', $contacted->fresh()->stage);
        $this->assertEqualsCanonicalizing([$silent->id], CrmCustomer::staleNew()->pluck('id')->all());
    }

    public function test_academic_staff_can_issue_temporary_password_for_student_account(): void
    {
        $class = $this->makeClass('F1A');
        $lead = $this->lead('consulting');
        $this->actingAs($this->sales)->post(route('crm.closing-wizard.store'), ['customer_id' => $lead->id, 'class_id' => $class->id, 'fee_paid_at_closing' => 0]);
        $enrollment = ClassEnrollment::where('customer_id', $lead->id)->firstOrFail();
        $account = $enrollment->student->user;
        $account->forceFill(['must_change_password' => false])->save();

        $response = $this->actingAs($this->academic)->post(route('crm.enrollments.reset-account', $enrollment))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('student_account_email', $account->email);
        $password = $response->getSession()->get('temporary_password');

        $account->refresh();
        $this->assertTrue(Hash::check($password, $account->password));
        $this->assertTrue((bool) $account->must_change_password);
        $this->assertTrue(CrmCustomerHistory::where('customer_id', $lead->id)->where('content', 'like', 'Cấp mật khẩu tạm%')->exists());
        $this->actingAs($this->academic)->get(route('crm.confirmations'))->assertOk()->assertSee($account->email);
    }

    public function test_temporary_password_is_not_issued_for_staff_accounts(): void
    {
        $class = $this->makeClass('F1A');
        $student = Student::create(['code' => 'HV-S1', 'name' => 'HV', 'branch_id' => $this->branch->id, 'phone' => '0900000002', 'status' => 'waiting_start', 'user_id' => $this->sales->id]);
        $lead = $this->lead('won');
        $enrollment = ClassEnrollment::create(['student_id' => $student->id, 'class_id' => $class->id, 'customer_id' => $lead->id, 'status' => 'pending']);
        $hash = $this->sales->password;

        $this->actingAs($this->academic)->post(route('crm.enrollments.reset-account', $enrollment))
            ->assertSessionHasErrors('enrollment');
        $this->assertSame($hash, $this->sales->fresh()->password);
    }

    /** @return array{0: CrmCustomer, 1: Student} */
    private function closeWithoutClass(): array
    {
        $lead = $this->lead('consulting');
        $this->actingAs($this->sales)->post(route('crm.closing-wizard.store'), ['customer_id' => $lead->id, 'course_id' => $this->course->id, 'fee_paid_at_closing' => 0])
            ->assertSessionHasNoErrors();
        $lead->refresh();
        $this->assertSame('waiting_class', $lead->stage);

        return [$lead, Student::findOrFail($lead->converted_student_id)];
    }

    private function makeClass(string $code, array $attributes = []): ClassModel
    {
        return ClassModel::create(array_merge([
            'code' => $code, 'name' => 'Lớp '.$code, 'course_id' => $this->course->id, 'branch_id' => $this->branch->id,
            'max_capacity' => 10, 'status' => 'active', 'tuition_fee' => 10000000,
        ], $attributes));
    }

    private function lead(string $stage): CrmCustomer
    {
        $phone = '09'.random_int(10000000, 99999999);

        return CrmCustomer::create([
            'code' => CrmCustomer::generateCode(),
            'name' => 'Lead '.$stage.' '.random_int(100, 999),
            'phone' => $phone,
            'phone_normalized' => $phone,
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->sales->id,
            'stage' => $stage,
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
