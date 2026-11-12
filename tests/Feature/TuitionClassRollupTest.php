<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Support\Navigation\SidebarMenu;
use App\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Rollup thu học phí theo lớp (TK-2026-0083): chỉ đọc, phạm vi chi nhánh ở server, chỉ phiếu đã duyệt. */
class TuitionClassRollupTest extends TestCase
{
    use RefreshDatabase;

    private Branch $a;

    private Branch $b;

    private ClassModel $classA;

    private ClassModel $classB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->a = Branch::create(['code' => 'RA', 'name' => 'Cơ sở A', 'is_active' => true]);
        $this->b = Branch::create(['code' => 'RB', 'name' => 'Cơ sở B', 'is_active' => true]);
        $course = Course::create(['code' => 'RC', 'name' => 'Khóa R', 'total_lessons' => 24, 'tuition_fee' => 1000000, 'is_active' => true]);
        $this->classA = ClassModel::create(['code' => 'LA', 'name' => 'Lớp A', 'course_id' => $course->id, 'branch_id' => $this->a->id]);
        $this->classB = ClassModel::create(['code' => 'LB', 'name' => 'Lớp B', 'course_id' => $course->id, 'branch_id' => $this->b->id]);
    }

    private function user(string $role, ?Branch $branch = null): User
    {
        $user = User::factory()->create(['branch_id' => $branch?->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function tuition(Student $student, ClassModel $class, array $receipts): StudentTuition
    {
        $tuition = StudentTuition::create([
            'student_id' => $student->id, 'class_id' => $class->id, 'branch_id' => $class->branch_id,
            'total_amount' => 1000000, 'final_amount' => 1000000, 'status' => 'partial',
        ]);
        foreach ($receipts as $i => [$amount, $status, $method, $invoice]) {
            TuitionReceipt::create([
                'receipt_number' => 'PT-R'.$tuition->id.'-'.$i, 'invoice_number' => $invoice,
                'student_tuition_id' => $tuition->id, 'student_id' => $student->id, 'amount' => $amount,
                'payment_method' => $method, 'payment_date' => now(), 'status' => $status,
            ]);
        }

        return $tuition;
    }

    private function student(string $name, ClassModel $class): Student
    {
        return Student::create(['code' => 'HV-'.$name, 'name' => $name, 'phone' => '09'.str_pad((string) crc32($name), 8, '0'), 'branch_id' => $class->branch_id, 'current_class_id' => $class->id]);
    }

    private function seedClassA(): void
    {
        $s1 = $this->student('An', $this->classA);
        $s2 = $this->student('Binh', $this->classA);
        $this->tuition($s1, $this->classA, [
            [600000, TuitionReceipt::STATUS_APPROVED, 'cash', 'HD-1'],
            [100000, TuitionReceipt::STATUS_APPROVED, 'transfer', 'HD-2'],
            [999000, TuitionReceipt::STATUS_PENDING, 'cash', 'HD-PEND'],
            [888000, TuitionReceipt::STATUS_CANCELLED, 'cash', 'HD-CANCEL'],
        ]);
        $this->tuition($s1, $this->classA, [[300000, TuitionReceipt::STATUS_APPROVED, 'vietqr', 'HD-3']]);
        $this->tuition($s2, $this->classA, [[200000, TuitionReceipt::STATUS_APPROVED, 'cash', 'HD-4']]);
    }

    public function test_admin_sees_totals_counting_only_approved_receipts(): void
    {
        $this->seedClassA();

        $this->actingAs($this->user(Roles::ADMIN))->get(route('tuition.class-rollup', ['class_id' => $this->classA->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tuition/ClassRollup')
                ->where('roundCount', 2)
                ->where('total', 1200000)
                ->where('totals', [900000, 300000])
                ->where('classes', fn ($classes) => collect($classes)->pluck('value')->contains($this->classA->id)
                    && collect($classes)->pluck('value')->contains($this->classB->id))
                ->has('rows', 2)
                ->where('rows.0.rounds.0.amount', 700000)
                ->where('rows.0.rounds.0.methods', 'Tiền mặt, Chuyển khoản')
                ->where('rows.0.rounds.0.invoices', 'HD-1, HD-2')
                ->where('rows.0.rounds.1.invoices', 'HD-3')
                ->where('rows.1.rounds.0.amount', 200000));
    }

    public function test_note_column_shows_latest_approved_receipt_note_with_fallbacks(): void
    {
        $s = $this->student('Cuong', $this->classA);
        $mk = fn (?string $notes) => StudentTuition::create([
            'student_id' => $s->id, 'class_id' => $this->classA->id, 'branch_id' => $this->a->id,
            'total_amount' => 1000000, 'final_amount' => 1000000, 'status' => 'completed', 'notes' => $notes,
        ]);
        $receipt = fn (StudentTuition $t, string $no, string $date, string $status, string $notes) => TuitionReceipt::create([
            'receipt_number' => $no, 'student_tuition_id' => $t->id, 'student_id' => $s->id, 'amount' => 100000,
            'payment_method' => 'cash', 'payment_date' => $date, 'status' => $status, 'notes' => $notes,
        ]);
        $t1 = $mk('HP note 1');
        $receipt($t1, 'N-1', '2026-01-10', TuitionReceipt::STATUS_APPROVED, 'old');
        $receipt($t1, 'N-2', '2026-02-10', TuitionReceipt::STATUS_APPROVED, 'newest by date');
        $receipt($t1, 'N-3', '2026-03-10', TuitionReceipt::STATUS_CANCELLED, 'cancelled ignored');
        $t2 = $mk('HP note 2');
        $receipt($t2, 'N-4', '2026-01-10', TuitionReceipt::STATUS_APPROVED, 'first same day');
        $receipt($t2, 'N-5', '2026-01-10', TuitionReceipt::STATUS_APPROVED, 'higher id same day');
        $mk('fallback HP note');
        $mk(null);

        $this->actingAs($this->user(Roles::ADMIN))->get(route('tuition.class-rollup', ['class_id' => $this->classA->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.rounds.0.note', 'newest by date')
                ->where('rows.0.rounds.1.note', 'higher id same day')
                ->where('rows.0.rounds.2.note', 'fallback HP note')
                ->where('rows.0.rounds.3.note', null)
                ->where('total', 400000));
    }

    public function test_class_without_tuition_items_lists_roster_with_no_rounds_and_empty_totals(): void
    {
        $this->student('An', $this->classA);
        $this->student('Binh', $this->classA);

        $this->actingAs($this->user(Roles::ADMIN))->get(route('tuition.class-rollup', ['class_id' => $this->classA->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tuition/ClassRollup')
                ->where('roundCount', 0)
                ->where('totals', [])
                ->where('total', 0)
                ->has('rows', 2));
    }

    public function test_manager_sees_only_own_branch_classes_and_gets_403_for_other_branch(): void
    {
        $this->seedClassA();
        $manager = $this->user(Roles::MANAGER, $this->a);

        $this->actingAs($manager)->get(route('tuition.class-rollup'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('classes', 1)->where('classes.0.value', $this->classA->id));
        $this->actingAs($manager)->get(route('tuition.class-rollup', ['class_id' => $this->classA->id]))->assertOk();
        $this->actingAs($manager)->get(route('tuition.class-rollup', ['class_id' => $this->classB->id]))->assertForbidden();
    }

    public function test_academic_staff_scoped_to_branch(): void
    {
        $staff = $this->user(Roles::ACADEMIC_STAFF, $this->b);

        $this->actingAs($staff)->get(route('tuition.class-rollup', ['class_id' => $this->classB->id]))->assertOk();
        $this->actingAs($staff)->get(route('tuition.class-rollup', ['class_id' => $this->classA->id]))->assertForbidden();
    }

    public function test_teacher_cannot_open_and_screen_is_read_only(): void
    {
        $this->actingAs($this->user(Roles::TEACHER_FULLTIME, $this->a))->get(route('tuition.class-rollup'))->assertForbidden();

        $this->seedClassA();
        $before = TuitionReceipt::count();
        $this->actingAs($this->user(Roles::ADMIN))->post('/tuition/class-rollup', [])->assertStatus(405);
        $this->assertSame($before, TuitionReceipt::count());
    }

    public function test_query_count_does_not_grow_with_students(): void
    {
        $count = function (int $students) {
            for ($i = 0; $i < $students; $i++) {
                $s = $this->student("S{$students}-{$i}", $this->classA);
                $this->tuition($s, $this->classA, [[1000, TuitionReceipt::STATUS_APPROVED, 'cash', null]]);
            }
            $admin = $this->user(Roles::ADMIN);
            \DB::enableQueryLog();
            \DB::flushQueryLog();
            $this->actingAs($admin)->get(route('tuition.class-rollup', ['class_id' => $this->classA->id]))->assertOk();

            return count(\DB::getQueryLog());
        };
        $small = $count(2);
        $large = $count(10);

        // 1st request also pays one-off cache warm-up queries, so the larger class must never need more.
        $this->assertLessThanOrEqual($small, $large);
    }

    public function test_menu_entry_visible_to_manager_and_academic_staff(): void
    {
        foreach ([Roles::MANAGER, Roles::ACADEMIC_STAFF, Roles::ADMIN] as $role) {
            $user = $this->user($role, $this->a);
            $this->assertTrue(app(SidebarMenu::class)->canSee($user, ['label' => 'x', 'route' => 'tuition.class-rollup']), $role);
        }
    }
}
