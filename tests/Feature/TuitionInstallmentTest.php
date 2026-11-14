<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** TK-2026-0116: tạo đợt học phí mới cho học viên đã có trong lớp (quyền, chi nhánh, validate, rollup). */
class TuitionInstallmentTest extends TestCase
{
    use RefreshDatabase;

    private Branch $a;

    private Branch $b;

    private ClassModel $classA;

    private ClassModel $classB;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->a = Branch::create(['code' => 'IA', 'name' => 'Cơ sở A', 'is_active' => true]);
        $this->b = Branch::create(['code' => 'IB', 'name' => 'Cơ sở B', 'is_active' => true]);
        $course = Course::create(['code' => 'IC', 'name' => 'Khóa I', 'total_lessons' => 24, 'tuition_fee' => 1000000, 'is_active' => true]);
        $this->classA = ClassModel::create(['code' => 'IL-A', 'name' => 'Lớp A', 'course_id' => $course->id, 'branch_id' => $this->a->id]);
        $this->classB = ClassModel::create(['code' => 'IL-B', 'name' => 'Lớp B', 'course_id' => $course->id, 'branch_id' => $this->b->id]);
        $this->student = Student::create(['code' => 'HV-I1', 'name' => 'An', 'phone' => '0911111111', 'branch_id' => $this->a->id, 'current_class_id' => $this->classA->id]);
    }

    private function user(string $role, ?Branch $branch = null): User
    {
        $user = User::factory()->create(['branch_id' => $branch?->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function payload(array $over = []): array
    {
        return [...[
            'class_id' => $this->classA->id, 'start_date' => '2026-03-01', 'end_date' => '2026-05-31',
            'session_count' => 12, 'unit_price' => 100000,
        ], ...$over];
    }

    private function url(?Student $s = null): string
    {
        return route('tuition.installments.store', ($s ?? $this->student)->id);
    }

    public function test_admin_creates_installment_with_audit_log(): void
    {
        $this->actingAs($this->user(Roles::ADMIN))->post($this->url(), $this->payload())->assertSessionHasNoErrors()->assertRedirect();

        $t = StudentTuition::query()->where('student_id', $this->student->id)->firstOrFail();
        $this->assertSame(1200000.0, (float) $t->final_amount);
        $this->assertSame(1200000.0, (float) $t->debt_amount);
        $this->assertSame(12, $t->session_count);
        $this->assertSame('2026-03-01', $t->start_date->toDateString());
        $this->assertSame($this->a->id, (int) $t->branch_id);
        $this->assertSame('unpaid', $t->status);
        $this->assertTrue(Activity::query()->where('log_name', 'tuition_installment')->where('subject_id', $t->id)->exists());
    }

    public function test_create_form_renders_for_admin(): void
    {
        $this->actingAs($this->user(Roles::ADMIN))->get(route('tuition.installments.create', $this->student->id))
            ->assertOk()->assertInertia(fn (Assert $p) => $p->component('Tuition/InstallmentForm')->has('classes', 1));
    }

    public function test_academic_staff_and_manager_are_forbidden_by_default(): void
    {
        foreach ([Roles::ACADEMIC_STAFF, Roles::MANAGER, Roles::ACCOUNTANT] as $role) {
            $this->actingAs($this->user($role, $this->a))->post($this->url(), $this->payload())->assertForbidden();
        }
        $this->assertSame(0, StudentTuition::count());
    }

    public function test_branch_scope_blocks_student_and_class_of_other_branch(): void
    {
        $user = $this->user(Roles::MANAGER, $this->a);
        $user->givePermissionTo('tuition.create_installment');
        $other = Student::create(['code' => 'HV-I2', 'name' => 'Binh', 'phone' => '0922222222', 'branch_id' => $this->b->id, 'current_class_id' => $this->classB->id]);

        $this->actingAs($user)->post($this->url($other), $this->payload(['class_id' => $this->classB->id]))->assertNotFound();
        $this->actingAs($user)->post($this->url(), $this->payload(['class_id' => $this->classB->id]))->assertForbidden();
        $this->actingAs($user)->post($this->url(), $this->payload())->assertSessionHasNoErrors();
        $this->assertSame(1, StudentTuition::count());
    }

    public function test_validation_rules(): void
    {
        $admin = $this->user(Roles::ADMIN);
        $this->actingAs($admin);

        $this->post($this->url(), $this->payload(['end_date' => '2026-02-01']))->assertSessionHasErrors('end_date');
        $this->post($this->url(), $this->payload(['unit_price' => -1]))->assertSessionHasErrors('unit_price');
        $this->post($this->url(), $this->payload(['session_count' => 0]))->assertSessionHasErrors('session_count');
        // Học viên chưa thuộc lớp này (cùng chi nhánh).
        $classA2 = ClassModel::create(['code' => 'IL-A2', 'name' => 'Lớp A2', 'course_id' => $this->classA->course_id, 'branch_id' => $this->a->id]);
        $this->post($this->url(), $this->payload(['class_id' => $classA2->id]))->assertSessionHasErrors('class_id');
        $this->assertSame(0, StudentTuition::count());

        $this->post($this->url(), $this->payload())->assertSessionHasNoErrors();
        // Trùng ngày bắt đầu, và chồng lấn khoảng thời gian.
        $this->post($this->url(), $this->payload(['end_date' => '2026-03-15']))->assertSessionHasErrors('start_date');
        $this->post($this->url(), $this->payload(['start_date' => '2026-05-01', 'end_date' => '2026-08-01']))->assertSessionHasErrors('start_date');
        $this->assertSame(1, StudentTuition::count());
        // Đợt liền sau (không chồng) hợp lệ.
        $this->post($this->url(), $this->payload(['start_date' => '2026-06-01', 'end_date' => '2026-08-31']))->assertSessionHasNoErrors();
        $this->assertSame(2, StudentTuition::count());
    }

    public function test_rollup_orders_blocks_by_start_date_and_totals_actual_collected(): void
    {
        $admin = $this->user(Roles::ADMIN);
        // Đợt đầu cũ (không ngày) + đợt tạo sau nhưng bắt đầu sớm hơn đợt 3 → thứ tự theo ngày, không theo id.
        $legacy = StudentTuition::create(['student_id' => $this->student->id, 'class_id' => $this->classA->id, 'branch_id' => $this->a->id, 'total_amount' => 1000000, 'final_amount' => 1000000, 'status' => 'unpaid']);
        $this->actingAs($admin)->post($this->url(), $this->payload(['start_date' => '2026-09-01', 'end_date' => '2026-11-30', 'unit_price' => 50000]))->assertSessionHasNoErrors();
        $this->post($this->url(), $this->payload(['start_date' => '2026-06-01', 'end_date' => '2026-08-31', 'unit_price' => 100000]))->assertSessionHasNoErrors();

        $paid = [500000, 300000, 200000];
        $tuitions = StudentTuition::query()->where('student_id', $this->student->id)
            ->orderByRaw('start_date IS NOT NULL')->orderBy('start_date')->get();
        $this->assertCount(3, $tuitions);
        $this->assertSame($legacy->id, $tuitions[0]->id);
        foreach ($tuitions as $i => $tuition) {
            TuitionReceipt::create([
                'receipt_number' => 'PT-I'.$i, 'student_tuition_id' => $tuition->id, 'student_id' => $this->student->id,
                'amount' => $paid[$i], 'payment_method' => 'cash', 'payment_date' => now(), 'status' => TuitionReceipt::STATUS_APPROVED,
            ]);
        }

        $this->get(route('tuition.class-rollup', ['class_id' => $this->classA->id]))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('roundCount', 3)
                ->where('totals', [500000, 300000, 200000])
                ->where('total', 1000000)
                ->where('rows.0.rounds.0.amount', 500000)
                ->where('rows.0.rounds.1.amount', 300000)
                ->where('rows.0.rounds.2.amount', 200000));
    }
}
