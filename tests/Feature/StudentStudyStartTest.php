<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Ngày bắt đầu học + thâm niên (tri ân học viên học lâu 3–5 năm): tự ghi nhận theo buổi có mặt đầu tiên,
 * Học vụ sửa tay được; hiện trên danh sách / hồ sơ học viên và cổng học viên.
 */
class StudentStudyStartTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow('2026-10-04 09:00:00');

        $this->branch = Branch::create(['name' => 'CN Hà Nội', 'code' => 'HN', 'is_active' => true]);
        $course = Course::create(['code' => 'IE', 'name' => 'IELTS', 'is_active' => true]);
        $this->class = ClassModel::create([
            'code' => 'HN-01', 'name' => 'Lớp Hà Nội 01', 'course_id' => $course->id,
            'branch_id' => $this->branch->id, 'status' => 'active', 'max_capacity' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(): User
    {
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $user->assignRole('academic_staff');

        return $user;
    }

    private function student(string $code, ?string $startedOn = null, array $extra = []): Student
    {
        return Student::create($extra + [
            'code' => $code, 'name' => "Học viên {$code}", 'phone' => '09'.str_pad((string) (crc32($code) % 100000000), 8, '0'),
            'branch_id' => $this->branch->id, 'current_class_id' => $this->class->id, 'status' => 'studying',
            'study_started_on' => $startedOn,
        ]);
    }

    private function attend(Student $student, string $date, string $status = 'present'): StudentAttendance
    {
        return StudentAttendance::create([
            'class_id' => $this->class->id, 'student_id' => $student->id, 'session_date' => $date, 'status' => $status,
        ]);
    }

    public function test_first_present_or_late_session_sets_the_study_start_date(): void
    {
        $student = $this->student('HV-A');

        $this->attend($student, '2026-09-05', 'absent');
        $this->assertNull($student->fresh()->study_started_on);

        $this->attend($student, '2026-09-12', 'late');
        $this->assertSame('2026-09-12', $student->fresh()->study_started_on->toDateString());

        // Buổi sau không đổi ngày; buổi nhập bù sớm hơn thì lùi về ngày sớm hơn.
        $this->attend($student, '2026-09-19');
        $this->assertSame('2026-09-12', $student->fresh()->study_started_on->toDateString());
        $this->attend($student, '2026-09-08');
        $this->assertSame('2026-09-08', $student->fresh()->study_started_on->toDateString());
    }

    public function test_manual_earlier_date_is_kept_when_attendance_is_recorded(): void
    {
        $student = $this->student('HV-OLD', '2021-03-01');

        $this->attend($student, '2026-09-12');

        $this->assertSame('2021-03-01', $student->fresh()->study_started_on->toDateString());
    }

    public function test_tenure_label_and_long_term_flag(): void
    {
        $this->assertSame('5 năm 7 tháng', $this->student('HV-5', '2021-03-01')->studyTenureLabel());
        $this->assertTrue($this->student('HV-3', '2023-10-04')->isLongTermStudent());
        $this->assertFalse($this->student('HV-2', '2023-10-05')->isLongTermStudent());
        $this->assertSame('5 tháng', $this->student('HV-M', '2026-05-01')->studyTenureLabel());
        $this->assertSame('Dưới 1 tháng', $this->student('HV-N', '2026-09-20')->studyTenureLabel());
        $this->assertNull($this->student('HV-X')->studyTenureLabel());
        // Đã thôi học: còn ngày bắt đầu nhưng không tính thâm niên.
        $this->assertNull($this->student('HV-D', '2020-01-01', ['status' => 'dropped'])->studyTenureLabel());
    }

    public function test_index_shows_start_date_and_filters_and_sorts_by_tenure(): void
    {
        $this->student('HV-NEW', '2026-01-10');
        $this->student('HV-THREE', '2023-06-01');
        $this->student('HV-FIVE', '2021-03-01');
        $this->student('HV-NONE');
        $this->student('HV-LEFT', '2019-01-01', ['status' => 'dropped']);
        $staff = $this->staff();

        $this->actingAs($staff)->get(route('students.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('students.data', fn ($rows) => collect($rows)->firstWhere('code', 'HV-FIVE')['study_started_on'] === '01/03/2021'
                    && collect($rows)->firstWhere('code', 'HV-FIVE')['tenure_label'] === '5 năm 7 tháng'
                    && collect($rows)->firstWhere('code', 'HV-FIVE')['long_term'] === true
                    && collect($rows)->firstWhere('code', 'HV-NONE')['study_started_on'] === null));

        $this->actingAs($staff)->get(route('students.index', ['tenure' => 3]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('students.data', fn ($rows) => collect($rows)->pluck('code')->sort()->values()->all() === ['HV-FIVE', 'HV-THREE']));

        $this->actingAs($staff)->get(route('students.index', ['tenure' => 5]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('students.data', fn ($rows) => collect($rows)->pluck('code')->all() === ['HV-FIVE']));

        $this->actingAs($staff)->get(route('students.index', ['sort' => 'tenure']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('students.data', fn ($rows) => collect($rows)->pluck('code')->all() === ['HV-LEFT', 'HV-FIVE', 'HV-THREE', 'HV-NEW', 'HV-NONE']));
    }

    public function test_academic_staff_can_set_and_clear_the_start_date_on_the_profile(): void
    {
        $student = $this->student('HV-EDIT');
        $this->attend($student, '2026-09-12');
        $staff = $this->staff();
        $payload = ['name' => $student->name, 'phone' => $student->phone];

        $this->actingAs($staff)->put(route('students.update', $student->id), $payload + ['study_started_on' => '2022-08-15'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2022-08-15', $student->fresh()->study_started_on->toDateString());

        $this->actingAs($staff)->get(route('students.show', $student->id))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('student.study_started_on', '15/08/2022')
                ->where('student.study_started_on_value', '2022-08-15')
                ->where('student.tenure_label', '4 năm 1 tháng')
                ->where('student.long_term', true));

        $this->actingAs($staff)->put(route('students.update', $student->id), $payload + ['study_started_on' => '2026-12-01'])
            ->assertSessionHasErrors('study_started_on');

        // Xóa trống → lấy lại theo buổi có mặt đầu tiên.
        $this->actingAs($staff)->put(route('students.update', $student->id), $payload + ['study_started_on' => ''])
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-09-12', $student->fresh()->study_started_on->toDateString());

        // Form không gửi ô này thì giữ nguyên.
        $this->actingAs($staff)->put(route('students.update', $student->id), $payload)->assertSessionHasNoErrors();
        $this->assertSame('2026-09-12', $student->fresh()->study_started_on->toDateString());
    }

    public function test_student_portal_home_shows_start_date_and_tenure(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->student('HV-PORTAL', '2022-09-05', ['user_id' => $user->id]);

        $this->actingAs($user)->get(route('portal.student.home'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portal/Home')
                ->where('student.study_started_on', '05/09/2022')
                ->where('student.tenure_label', '4 năm'));
    }
}
