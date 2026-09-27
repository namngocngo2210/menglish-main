<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rà soát đợt 1-2 (CRM): sửa khách không đổi người phụ trách, chi nhánh theo phạm vi, lớp gợi ý cho Chờ xếp lớp,
 * báo cáo tính cả khách Chờ xếp lớp là đã chốt.
 */
class ReviewRoundCrmTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    private User $academic;

    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CSA', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'Cơ sở B', 'code' => 'CSB', 'is_active' => true]);
        $this->academic = $this->userWithRole('academic_staff');
        $this->sales = $this->userWithRole('sales_consultant');
    }

    public function test_edit_form_keeps_assignee_who_cannot_be_assigned(): void
    {
        // Học vụ tự tạo khách → là người phụ trách dù không có quyền lead.be_assigned.
        $lead = $this->lead('consulting', ['assigned_user_id' => $this->academic->id]);

        $editForm = $this->actingAs($this->academic)->get(route('crm.customers.show', $lead->id))->assertOk()->viewData('editForm');
        $this->assertTrue($editForm['salesUsers']->contains('id', $this->academic->id));

        $this->actingAs($this->academic)->put(route('crm.customers.update', $lead->id), [
            'name' => $lead->name, 'phone' => '0911222333', 'source' => 'Facebook',
            'branch_id' => $this->branch->id, 'assigned_user_id' => $this->academic->id,
        ])->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame('0911222333', $lead->phone);
        $this->assertSame($this->academic->id, $lead->assigned_user_id);
    }

    public function test_branch_scoped_user_cannot_create_lead_in_other_branch(): void
    {
        $options = $this->actingAs($this->academic)->get(route('crm.customers.create'))->assertOk()->viewData('branches');
        $this->assertSame([$this->branch->id], $options->pluck('id')->all());

        $this->actingAs($this->academic)->post(route('crm.customers.store'), [
            'name' => 'Khách chi nhánh khác', 'phone' => '0911000111', 'source' => 'Facebook', 'branch_id' => $this->otherBranch->id,
        ])->assertSessionHasErrors('branch_id');
        $this->assertDatabaseMissing('crm_customers', ['phone' => '0911000111']);
    }

    public function test_waiting_list_hides_upcoming_class_past_its_start_date(): void
    {
        $course = Course::create(['code' => 'FAM1', 'name' => 'Starters FAM 1', 'tuition_fee' => 1000, 'total_lessons' => 48, 'is_active' => true]);
        $student = Student::create(['code' => 'HV-W1', 'name' => 'HV chờ', 'phone' => '0900000001', 'branch_id' => $this->branch->id, 'status' => 'waiting_start']);
        $lead = $this->lead('waiting_class', ['converted_student_id' => $student->id, 'waiting_course_id' => $course->id]);
        $ok = $this->makeClass('UP-OK', 'upcoming', $course, now()->addDays(5));
        $stale = $this->makeClass('UP-OLD', 'upcoming', $course, now()->subDays(2));

        $matches = $this->actingAs($this->academic)->get(route('crm.waiting-list'))->assertOk()
            ->viewData('matchingClassesByLead')->get($lead->id)->pluck('id')->all();

        $this->assertContains($ok->id, $matches);
        $this->assertNotContains($stale->id, $matches);
    }

    public function test_report_counts_waiting_class_as_closed(): void
    {
        $this->lead('waiting_class', ['converted_at' => now()]);
        $this->lead('won', ['converted_at' => now()]);
        $this->lead('consulting');

        $response = $this->actingAs($this->academic)->get(route('crm.reports'))->assertOk();
        $this->assertSame(2, $response->viewData('metricWonDeals'));
    }

    private function makeClass(string $code, string $status, Course $course, $start): ClassModel
    {
        return ClassModel::create([
            'code' => $code, 'name' => 'Lớp '.$code, 'course_id' => $course->id, 'branch_id' => $this->branch->id,
            'max_capacity' => 10, 'status' => $status, 'tuition_fee' => 1000, 'start_date' => $start,
        ]);
    }

    private function lead(string $stage, array $attributes = []): CrmCustomer
    {
        $phone = '09'.random_int(10000000, 99999999);

        return CrmCustomer::create(array_merge([
            'code' => CrmCustomer::generateCode(),
            'name' => 'Lead '.$stage,
            'phone' => $phone,
            'phone_normalized' => $phone,
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->sales->id,
            'source' => 'Facebook',
            'stage' => $stage,
        ], $attributes));
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
