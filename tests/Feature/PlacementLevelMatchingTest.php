<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\CrmCustomer;
use App\Models\PlacementTest;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Xếp lớp theo cấp độ: lớp xếp được cho khách Chờ xếp lớp khi đúng khóa đã chốt, hoặc cùng cấp độ — trình độ của
 * khóa đã chốt, hoặc trình độ ghép với cấp độ test đầu vào (Cấu hình Trình độ / trình độ trùng tên cấp độ).
 */
class PlacementLevelMatchingTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $sales;

    private User $academic;

    private CourseLevel $starters;

    private CourseLevel $movers;

    private Course $course;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở cấp độ', 'code' => 'CD', 'is_active' => true]);
        $this->sales = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->sales->assignRole('sales_consultant');
        $this->academic = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->academic->assignRole('academic_staff');

        $this->starters = CourseLevel::create(['code' => 'STR', 'name' => 'Starters', 'target' => 'Pre-A1', 'lessons_count' => 36, 'is_active' => true]);
        $this->movers = CourseLevel::create(['code' => 'MOV', 'name' => 'Movers', 'target' => 'A1', 'lessons_count' => 36, 'is_active' => true]);
        $this->course = Course::create(['code' => 'FAM1', 'name' => 'Starters FAM 1', 'course_level_id' => $this->starters->id, 'tuition_fee' => 10000000, 'is_active' => true]);
        $this->bank = BankAccount::create([
            'bank_code' => 'VCB', 'bank_name' => 'Vietcombank', 'account_number' => '123456',
            'account_holder' => 'MENGLISH', 'branch_id' => $this->branch->id, 'is_default_vietqr' => true, 'is_active' => true,
        ]);
    }

    public function test_waiting_list_offers_classes_of_the_closed_course_level_even_with_another_course(): void
    {
        $lead = $this->closeWithoutClass();
        // Lớp tạo với chương trình khác (hoặc chương trình cũ không gắn khóa) nhưng cấp độ Starters.
        $sameLevel = $this->makeClass('STR-A', null, 'STR');
        $otherCourse = Course::create(['code' => 'YLE', 'name' => 'Cambridge YLE', 'is_active' => true]);
        $sameLevelOtherCourse = $this->makeClass('STR-B', $otherCourse, 'STR');
        $wrongLevel = $this->makeClass('MOV-A', $otherCourse, 'MOV');

        $matches = $this->waitingMatches($lead);
        $this->assertContains($sameLevel->id, $matches);
        $this->assertContains($sameLevelOtherCourse->id, $matches);
        $this->assertNotContains($wrongLevel->id, $matches);

        $this->actingAs($this->academic)->post(route('crm.customers.assign-class', $lead), ['class_id' => $wrongLevel->id])
            ->assertSessionHasErrors('class_id');
        $this->actingAs($this->academic)->post(route('crm.customers.assign-class', $lead), ['class_id' => $sameLevelOtherCourse->id])
            ->assertSessionHasNoErrors();
        $this->assertSame('won', $lead->fresh()->stage);
    }

    public function test_class_without_level_falls_back_to_its_course_level(): void
    {
        $lead = $this->closeWithoutClass();
        $otherCourse = Course::create(['code' => 'STR2', 'name' => 'Starters 2', 'course_level_id' => $this->starters->id, 'is_active' => true]);
        $class = $this->makeClass('STR-C', $otherCourse, null);

        $this->assertContains($class->id, $this->waitingMatches($lead));
    }

    public function test_test_grade_mapped_on_course_level_widens_placement(): void
    {
        $this->movers->update(['grade_levels' => ['lop_3']]);
        $lead = $this->closeWithoutClass(PlacementTest::create(['code' => 'TEST-G3-G4', 'title' => 'Đề lớp 3', 'grade_level' => 'lop_3', 'is_active' => true]));
        $movers = $this->makeClass('MOV-B', null, 'MOV');

        $rows = collect($this->actingAs($this->academic)->get(route('crm.waiting-list'))->assertOk()->inertiaProps('waitingLeads'));
        $row = $rows->firstWhere('id', $lead->id);
        $this->assertContains($movers->id, collect($row['matches'])->pluck('value')->all());
        $this->assertEqualsCanonicalizing(['Starters', 'Movers'], $row['levels']);
    }

    public function test_course_level_named_like_the_test_grade_matches_without_config(): void
    {
        $grade3 = CourseLevel::create(['code' => 'L3', 'name' => 'Lớp 3', 'target' => 'Lớp 3', 'lessons_count' => 30, 'is_active' => true]);
        $lead = $this->closeWithoutClass(PlacementTest::create(['code' => 'PT-L3', 'title' => 'Đề lớp 3', 'grade_level' => 'lop_3', 'is_active' => true]));
        $class = $this->makeClass('L3-A', null, $grade3->code);

        $this->assertContains($class->id, $this->waitingMatches($lead));
    }

    public function test_closing_wizard_flags_classes_of_the_test_grade(): void
    {
        $this->movers->update(['grade_levels' => ['lop_4']]);
        $test = PlacementTest::create(['code' => 'PT-L4', 'title' => 'Đề lớp 4', 'grade_level' => 'lop_4', 'is_active' => true]);
        $lead = $this->lead('consulting', $test);
        $movers = $this->makeClass('MOV-W', null, 'MOV');
        $starters = $this->makeClass('STR-W', $this->course, 'STR');

        $response = $this->actingAs($this->sales)->get(route('crm.closing-wizard', ['customer_id' => $lead->id]))->assertOk();
        $classes = collect($response->inertiaProps('classes'))->keyBy('id');
        $this->assertTrue($classes[$movers->id]['level_match']);
        $this->assertSame('Movers', $classes[$movers->id]['level_name']);
        $this->assertFalse($classes[$starters->id]['level_match']);
        $this->assertSame($movers->id, $response->inertiaProps('defaultClassId'));
        $this->assertSame('Lớp 4', $response->inertiaProps('customers.0.grade_label'));
    }

    public function test_enrollment_screen_limits_classes_by_course_or_level(): void
    {
        $lead = $this->closeWithoutClass();
        $sameLevel = $this->makeClass('STR-E', null, 'STR');
        $wrongLevel = $this->makeClass('MOV-E', null, 'MOV');

        $rules = $this->actingAs($this->academic)->get(route('students.enrollments'))->assertOk()->inertiaProps('placementRules');
        $allowed = $rules[$lead->converted_student_id]['class_ids'];
        $this->assertContains($sameLevel->id, $allowed);
        $this->assertNotContains($wrongLevel->id, $allowed);

        $this->actingAs($this->academic)->post(route('students.enrollments.store'), ['student_id' => $lead->converted_student_id, 'class_id' => $wrongLevel->id])
            ->assertSessionHasErrors('class_id');
    }

    public function test_course_level_form_saves_test_grades(): void
    {
        $admin = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $admin->givePermissionTo(['level.view', 'level.update']);

        $this->actingAs($admin)->put(route('course-levels.update', $this->movers->id), [
            'name' => 'Movers', 'target' => 'A1', 'lessons_count' => 36, 'grade_levels' => ['lop_4', 'lop_3'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(['lop_3', 'lop_4'], $this->movers->fresh()->grade_levels);

        $this->actingAs($admin)->put(route('course-levels.update', $this->movers->id), [
            'name' => 'Movers', 'target' => 'A1', 'lessons_count' => 36, 'grade_levels' => ['lop_10'],
        ])->assertSessionHasErrors('grade_levels.0');

        $this->actingAs($admin)->put(route('course-levels.update', $this->movers->id), [
            'name' => 'Movers', 'target' => 'A1', 'lessons_count' => 36,
        ])->assertSessionHasNoErrors();
        $this->assertNull($this->movers->fresh()->grade_levels);
    }

    private function waitingMatches(CrmCustomer $lead): array
    {
        $row = collect($this->actingAs($this->academic)->get(route('crm.waiting-list'))->assertOk()->inertiaProps('waitingLeads'))->firstWhere('id', $lead->id);

        return collect($row['matches'])->pluck('value')->all();
    }

    private function closeWithoutClass(?PlacementTest $test = null): CrmCustomer
    {
        $lead = $this->lead('consulting', $test);
        $this->actingAs($this->sales)->post(route('crm.closing-wizard.store'), [
            'customer_id' => $lead->id, 'class_id' => null, 'course_id' => $this->course->id,
            'fee_paid_at_closing' => 0, 'paid_amount' => 0, 'payment_method' => 'transfer', 'bank_account_id' => $this->bank->id,
        ])->assertSessionHasNoErrors();
        $lead->refresh();
        $this->assertSame('waiting_class', $lead->stage);
        $this->assertNotNull(Student::find($lead->converted_student_id));

        return $lead;
    }

    private function makeClass(string $code, ?Course $course, ?string $level): ClassModel
    {
        return ClassModel::create([
            'code' => $code, 'name' => 'Lớp '.$code, 'course_id' => $course?->id, 'level' => $level,
            'branch_id' => $this->branch->id, 'max_capacity' => 10, 'status' => 'active',
        ]);
    }

    private function lead(string $stage, ?PlacementTest $test = null): CrmCustomer
    {
        return CrmCustomer::create([
            'code' => CrmCustomer::generateCode(),
            'name' => 'Khách cấp độ '.$stage,
            'phone' => '09'.random_int(10000000, 99999999),
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->sales->id,
            'assigned_test_id' => $test?->id,
            'stage' => $stage,
        ]);
    }
}
