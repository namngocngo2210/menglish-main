<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CrmTrialBooking;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusDocument;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Support\Practice\SchoolGrade;
use App\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StagingTestDataSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runSeederForReal(PermissionSeeder::class);
        $this->runSeederForReal(RoleSeeder::class);
        Storage::fake(SyllabusDocument::DISK);

        $branch = Branch::create(['code' => 'CG', 'name' => 'Cầu Giấy', 'is_active' => true]);
        $course = Course::create(['code' => 'DEMO-FAM1', 'name' => '# Starters (FAM 1)', 'tuition_fee' => 9000000, 'total_lessons' => 36, 'is_active' => true]);
        $teacher = User::factory()->create(['branch_id' => $branch->id]);
        $teacher->assignRole(Roles::TEACHER_PARTTIME);
        User::factory()->create(['branch_id' => $branch->id])->assignRole(Roles::SALES_CONSULTANT);
        $class = ClassModel::create([
            'code' => 'DEMO-CG-FAM1', 'name' => '# Starters FAM 1 · K26 (CG)', 'course_id' => $course->id, 'branch_id' => $branch->id,
            'teacher_id' => $teacher->id, 'status' => 'active', 'tuition_fee' => 9000000,
            'start_date' => today()->subWeeks(2)->toDateString(), 'end_date' => today()->addWeeks(8)->toDateString(),
        ]);
        ClassSession::create([
            'class_id' => $class->id, 'branch_id' => $branch->id, 'date' => today()->addDays(2)->toDateString(),
            'shift_name' => 'Ca chiều', 'start_time' => '17:30', 'end_time' => '19:00', 'teacher_id' => $teacher->id, 'status' => 'scheduled',
        ]);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'users' => User::count(), 'students' => Student::count(), 'classes' => ClassModel::count(), 'sessions' => ClassSession::count(),
            'tuitions' => StudentTuition::count(), 'bookings' => CrmTrialBooking::count(), 'timesheets' => TeacherTimesheet::count(),
            'assignments' => SyllabusAssignment::count(), 'documents' => SyllabusDocument::count(),
        ];
    }

    public function test_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';
        $before = $this->counts();

        $this->artisan('staging:seed-test-data')->assertFailed();

        $this->assertSame($before, $this->counts());
        $this->assertNull(User::where('email', 'nn-x.gd@test.menglish')->first());
    }

    public function test_stops_when_demo_base_class_is_missing(): void
    {
        ClassModel::where('code', 'DEMO-CG-FAM1')->delete();

        $this->artisan('staging:seed-test-data')->assertFailed();
        $this->assertNull(User::where('email', 'nn-x.gd@test.menglish')->first());
    }

    public function test_is_idempotent_and_leaves_golden_accounts_alone(): void
    {
        $this->artisan('staging:seed-test-data')->assertSuccessful();
        $first = $this->counts();
        $this->artisan('staging:seed-test-data')->assertSuccessful();

        $this->assertSame($first, $this->counts());
        $this->assertNull(User::where('email', 'like', '%-a.gd@test.menglish')->first());
    }

    public function test_creates_the_data_each_blocked_case_needs(): void
    {
        $this->artisan('staging:seed-test-data', ['--birthday' => '2016-03-09'])->assertSuccessful();

        $young = Student::where('code', 'TS-HS-01')->firstOrFail();
        $login = User::findOrFail($young->user_id);
        $this->assertTrue($young->dob->gt(now()->subYears(10)));
        $this->assertFalse($login->must_change_password);
        $this->assertTrue($login->hasRole(Roles::STUDENT));
        $this->assertSame(6, SchoolGrade::forStudent($young));
        $this->assertDatabaseHas('practice_exams', ['code' => 'TS-601', 'status' => 'published']);

        $birthday = Student::where('code', 'TS-HS-02')->firstOrFail();
        $this->assertSame('03-09', $birthday->dob->format('m-d'));

        $lead = User::where('email', 'hocthuat-gv.test@test.menglish')->firstOrFail();
        $this->assertTrue($lead->hasRole(Roles::ACADEMIC_LEAD));
        $this->assertTrue($lead->academic_teaching);
        $this->assertTrue(ClassModel::where('teacher_id', $lead->id)->exists());

        $base = ClassModel::where('code', 'DEMO-CG-FAM1')->firstOrFail();
        $booking = CrmTrialBooking::firstOrFail();
        $this->assertSame($base->id, $booking->class_id);
        $this->assertSame('scheduled', $booking->status);

        $waiting = Student::where('code', 'TS-HS-03')->firstOrFail();
        $this->assertSame('waiting_start', $waiting->status);
        $this->assertTrue(SyllabusAssignment::open()->where('class_id', $waiting->current_class_id)->exists());

        $this->assertTrue(SyllabusDocument::where('extension', 'wav')->exists());

        $gvnn = User::where('email', 'nn-x.gd@test.menglish')->firstOrFail();
        $this->assertTrue($gvnn->hasRole(Roles::FOREIGN_TEACHER));
        $this->assertTrue(ClassModel::where('foreign_teacher_id', $gvnn->id)->exists());
        $this->assertTrue(TeacherTimesheet::where('user_id', $gvnn->id)->exists());

        $due = StudentTuition::where('student_id', Student::where('code', 'TS-HS-04')->value('id'))->firstOrFail();
        $this->assertGreaterThan(0, $due->debt_amount);
        $this->assertTrue(now()->addDays(14)->gte($due->due_date));
        $summer = Student::where('code', 'TS-HS-05')->firstOrFail();
        $this->assertSame('summer_break', $summer->status);
        $this->assertSame(36, StudentTuition::where('student_id', $summer->id)->value('session_count'));
    }
}
