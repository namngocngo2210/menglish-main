<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class StudentSchoolGradeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-23 09:00:00'));
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-SG', 'is_active' => true]);
        $this->admin = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $this->admin->assignRole('admin');
    }

    private function student(?string $dob, int $adjust = 0): Student
    {
        return Student::create([
            'code' => 'HS-SG-'.Student::count(), 'name' => 'Học Viên', 'phone' => '0911000'.random_int(100, 999),
            'branch_id' => $this->branch->id, 'status' => 'studying', 'dob' => $dob, 'grade_adjust' => $adjust,
        ]);
    }

    private function payload(Student $s, array $extra = []): array
    {
        return $extra + ['name' => $s->name, 'phone' => $s->phone];
    }

    public function test_profile_update_ignores_grade_and_dob(): void
    {
        // TK-2026-0139: khối / ngày sinh không sửa được qua hồ sơ.
        $s = $this->student('2014-03-10');
        $this->actingAs($this->admin)->put(route('students.update', $s->id), $this->payload($s, ['school_grade' => 6, 'dob' => '2015-01-01']))
            ->assertSessionHasNoErrors();
        $s->refresh();
        $this->assertSame(0, (int) $s->grade_adjust);
        $this->assertSame('2014-03-10', $s->dob->toDateString());
    }

    public function test_show_exposes_school_grade_and_year(): void
    {
        $s = $this->student('2014-03-10');
        $this->actingAs($this->admin)->get(route('students.show', $s->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('student.school_grade', 7)->where('student.school_year', '2026–2027'));
    }
}
