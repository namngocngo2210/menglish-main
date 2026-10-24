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

    public function test_setting_grade_stores_offset_from_dob_grade(): void
    {
        $s = $this->student('2014-03-10');
        $this->actingAs($this->admin)->put(route('students.update', $s->id), $this->payload($s, ['school_grade' => 6]))
            ->assertSessionHasNoErrors();
        $this->assertSame(-1, $s->fresh()->grade_adjust);
    }

    public function test_grade_equal_to_computed_stores_zero(): void
    {
        $s = $this->student('2014-03-10', -1);
        $this->actingAs($this->admin)->put(route('students.update', $s->id), $this->payload($s, ['school_grade' => 7]))
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $s->fresh()->grade_adjust);
    }

    public function test_grade_uses_dob_from_same_payload(): void
    {
        $s = $this->student(null);
        $this->actingAs($this->admin)->put(route('students.update', $s->id), $this->payload($s, ['dob' => '2014-03-10', 'school_grade' => 8]))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $s->fresh()->grade_adjust);
    }

    public function test_empty_grade_keeps_adjust(): void
    {
        $s = $this->student('2014-03-10', -1);
        $this->actingAs($this->admin)->put(route('students.update', $s->id), $this->payload($s, ['school_grade' => '']))
            ->assertSessionHasNoErrors();
        $this->assertSame(-1, $s->fresh()->grade_adjust);
    }

    public function test_grade_without_dob_is_rejected(): void
    {
        $s = $this->student(null);
        $this->actingAs($this->admin)->put(route('students.update', $s->id), $this->payload($s, ['school_grade' => 6]))
            ->assertSessionHasErrors(['school_grade' => 'Cần ngày sinh để đặt khối.']);
        $this->assertSame(0, $s->fresh()->grade_adjust);
    }

    public function test_correcting_dob_with_unchanged_displayed_grade_follows_new_dob(): void
    {
        $s = $this->student('2014-03-10', -1);
        $displayed = 6;
        $this->actingAs($this->admin)->put(route('students.update', $s->id), $this->payload($s, ['dob' => '2015-03-10', 'school_grade' => $displayed]))
            ->assertSessionHasNoErrors();
        $s->refresh();
        $this->assertSame(-1, $s->grade_adjust);
        $this->assertSame(5, \App\Support\Practice\SchoolGrade::forStudent($s));
    }

    public function test_grade_out_of_range_is_rejected(): void
    {
        $s = $this->student('2014-03-10');
        foreach ([0, 13] as $g) {
            $this->actingAs($this->admin)->put(route('students.update', $s->id), $this->payload($s, ['school_grade' => $g]))
                ->assertSessionHasErrors('school_grade');
        }
    }

    public function test_show_exposes_school_grade_and_year(): void
    {
        $s = $this->student('2014-03-10');
        $this->actingAs($this->admin)->get(route('students.show', $s->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('student.school_grade', 7)->where('student.school_year', '2026–2027'));
    }
}
