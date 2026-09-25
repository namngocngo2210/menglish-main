<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentBusinessTest extends TestCase
{
    use RefreshDatabase;

    private User $academicOfficer;
    private Branch $branchHanoi;
    private Branch $branchHcm;
    private Course $course;
    private ClassModel $classHanoi;
    private ClassModel $classHcm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branchHanoi = Branch::create([
            'name' => 'MEnglish Hà Nội - Cầu Giấy',
            'code' => 'HN-CG',
            'address' => '123 Cầu Giấy, Hà Nội',
            'phone' => '02411112222',
            'is_active' => true,
        ]);

        $this->branchHcm = Branch::create([
            'name' => 'MEnglish TP.HCM - Quận 1',
            'code' => 'HCM-Q1',
            'address' => '456 Lê Thánh Tôn, Quận 1, TP.HCM',
            'phone' => '02811112222',
            'is_active' => true,
        ]);

        $this->academicOfficer = User::factory()->create([
            'branch_id' => $this->branchHanoi->id,
            'name' => 'Giáo vụ MEnglish',
            'is_active' => true,
        ]);
        $this->academicOfficer->assignRole('academic_staff');

        $this->course = Course::create([
            'code' => 'IELTS-MASTER',
            'name' => 'IELTS Master 7.5+',
            'tuition_fee' => 20000000,
            'duration_months' => 6,
            'is_active' => true,
        ]);

        $this->classHanoi = ClassModel::create([
            'code' => 'IE-HN-01',
            'name' => 'Lớp IELTS Master HN01',
            'course_id' => $this->course->id,
            'branch_id' => $this->branchHanoi->id,
            'status' => 'active',
        ]);

        $this->classHcm = ClassModel::create([
            'code' => 'IE-HCM-01',
            'name' => 'Lớp IELTS Master HCM01',
            'course_id' => $this->course->id,
            'branch_id' => $this->branchHcm->id,
            'status' => 'active',
        ]);
    }

    // =========================================================================
    // a. Student creation, updates, attendance rate, homework rate & labels
    // =========================================================================

    public function test_can_create_student_profile_with_generated_code(): void
    {
        $payload = [
            'name' => 'Đinh Công Thành',
            'phone' => '0934567890',
            'email' => 'congthanh.dinh@gmail.com',
            'branch_id' => $this->branchHanoi->id,
            'current_class_id' => $this->classHanoi->id,
            'target' => 'IELTS 7.5 Overall',
        ];

        $response = $this->actingAs($this->academicOfficer)->post(route('students.store'), $payload);

        $response->assertRedirect(route('students.index'));

        $this->assertDatabaseHas('students', [
            'name' => 'Đinh Công Thành',
            'phone' => '0934567890',
            'email' => 'congthanh.dinh@gmail.com',
            'branch_id' => $this->branchHanoi->id,
            'current_class_id' => $this->classHanoi->id,
            'target' => 'IELTS 7.5 Overall',
            'status' => 'studying',
        ]);

        $student = Student::where('phone', '0934567890')->first();
        $this->assertNotNull($student);
        $this->assertStringStartsWith('HV-', $student->code);
    }

    public function test_student_creation_validation_rules(): void
    {
        $response = $this->actingAs($this->academicOfficer)->post(route('students.store'), [
            'name' => '',
            'phone' => '',
            'email' => 'invalid-email-address',
            'branch_id' => 999999,
        ]);

        $response->assertSessionHasErrors(['name', 'phone', 'email', 'branch_id']);
    }

    public function test_can_update_and_soft_delete_student_profile(): void
    {
        $student = Student::create([
            'code' => 'HV-00050',
            'name' => 'Nguyễn Thu Trang',
            'phone' => '0945678123',
            'email' => 'thutrang.nguyen@gmail.com',
            'branch_id' => $this->branchHanoi->id,
            'status' => 'studying',
        ]);

        // Update profile
        $responseUpdate = $this->actingAs($this->academicOfficer)->put(route('students.update', $student->id), [
            'name' => 'Nguyễn Thu Trang (Đã đổi tên)',
            'phone' => '0945678123',
            'email' => 'thutrang.nguyen@gmail.com',
            'target' => 'IELTS 8.0 Overall',
            'status' => 'completed',
            'notes' => 'Đã hoàn thành khóa học và đạt chứng chỉ IELTS 8.0',
        ]);

        $responseUpdate->assertRedirect(route('students.show', $student->id));

        $student->refresh();
        $this->assertEquals('Nguyễn Thu Trang (Đã đổi tên)', $student->name);
        $this->assertEquals('IELTS 8.0 Overall', $student->target);
        $this->assertEquals('completed', $student->status);
        $this->assertEquals('Hoàn thành khóa học', $student->status_label);

        // Soft delete profile
        $responseDelete = $this->actingAs($this->academicOfficer)->delete(route('students.destroy', $student->id));
        $responseDelete->assertRedirect(route('students.index'));
        $this->assertSoftDeleted('students', ['id' => $student->id]);
    }

    public function test_attendance_rate_and_homework_rate_computations_and_badges(): void
    {
        // 1. Student with 0 lessons
        $newStudent = new Student([
            'attended_lessons' => 0,
            'total_lessons' => 0,
            'homework_rate' => 100.0,
            'status' => 'studying',
        ]);
        $this->assertEquals('100%', $newStudent->attendance_rate);
        $this->assertEquals('Đang học', $newStudent->status_label);
        $this->assertStringContainsString('bg-emerald-50', $newStudent->status_badge);

        // 2. Student with 18/20 lessons
        $activeStudent = new Student([
            'attended_lessons' => 18,
            'total_lessons' => 20,
            'homework_rate' => 95.5,
            'status' => 'deferred',
        ]);
        $this->assertEquals('18/20 (90%)', $activeStudent->attendance_rate);
        $this->assertEquals('Bảo lưu', $activeStudent->status_label);
        $this->assertStringContainsString('bg-amber-50', $activeStudent->status_badge);

        // 3. Dropped status
        $droppedStudent = new Student([
            'status' => 'dropped',
        ]);
        $this->assertEquals('Thôi học', $droppedStudent->status_label);
        $this->assertStringContainsString('bg-rose-50', $droppedStudent->status_badge);
    }

    // =========================================================================
    // b. Class enrollment and branch scoping
    // =========================================================================

    public function test_can_enroll_student_to_class(): void
    {
        $student = Student::create([
            'code' => 'HV-00060',
            'name' => 'Lý Gia Thành',
            'phone' => '0987111222',
            'branch_id' => $this->branchHanoi->id,
            'status' => 'studying',
        ]);

        $response = $this->actingAs($this->academicOfficer)->post(route('students.enrollments.store'), [
            'student_id' => $student->id,
            'class_id' => $this->classHanoi->id,
        ]);

        $response->assertRedirect(route('students.enrollments'));

        $this->assertDatabaseHas('class_enrollments', [
            'student_id' => $student->id,
            'class_id' => $this->classHanoi->id,
            'curriculum_delivered' => false,
            'zalo_group_added' => false,
            'status' => 'pending',
        ]);

        $student->refresh();
        $this->assertEquals($this->classHanoi->id, $student->current_class_id);
    }

    public function test_students_listing_and_branch_scoping(): void
    {
        $stuHanoi = Student::create([
            'code' => 'HV-HN-01',
            'name' => 'Nguyễn Văn Hà Nội',
            'phone' => '0911000111',
            'branch_id' => $this->branchHanoi->id,
            'current_class_id' => $this->classHanoi->id,
            'status' => 'studying',
        ]);

        $stuHcm = Student::create([
            'code' => 'HV-HCM-01',
            'name' => 'Lê Sài Gòn',
            'phone' => '0922000222',
            'branch_id' => $this->branchHcm->id,
            'current_class_id' => $this->classHcm->id,
            'status' => 'completed',
        ]);

        // Filter by branch Hanoi
        $responseHanoi = $this->actingAs($this->academicOfficer)->get(route('students.index', [
            'branch_id' => $this->branchHanoi->id,
        ]));
        $responseHanoi->assertOk();
        $responseHanoi->assertSee('Nguyễn Văn Hà Nội');
        $responseHanoi->assertDontSee('Lê Sài Gòn');

        // Filter by search name
        $responseSearch = $this->actingAs($this->academicOfficer)->get(route('students.index', [
            'search' => 'Sài Gòn',
        ]));
        $responseSearch->assertOk();
        $responseSearch->assertSee('Lê Sài Gòn');
        $responseSearch->assertDontSee('Nguyễn Văn Hà Nội');

        // Scoped student detail page
        $responseScoped = $this->actingAs($this->academicOfficer)->get(route('students.scoped', $stuHanoi->id));
        $responseScoped->assertOk();
        $responseScoped->assertSee('Nguyễn Văn Hà Nội');

        // Scoped student fallback for demo HV-01 route
        $responseDemo = $this->actingAs($this->academicOfficer)->get(route('students.scoped', 'HV-01'));
        $responseDemo->assertOk();
        $responseDemo->assertSee('Phân quyền');
    }

    public function test_can_view_comprehensive_student_show_page(): void
    {
        $student = Student::create([
            'code' => 'HV-00099',
            'name' => 'Trần Bảo Anh',
            'phone' => '0988776655',
            'email' => 'baoanh@gmail.com',
            'branch_id' => $this->branchHanoi->id,
            'current_class_id' => $this->classHanoi->id,
            'status' => 'studying',
        ]);

        $response = $this->actingAs($this->academicOfficer)->get(route('students.show', $student->id));
        $response->assertOk();
        $response->assertSee('Trần Bảo Anh');
        $response->assertSee('HV-00099');
        $response->assertSee('Đang học');
    }

    public function test_student_profile_has_exactly_the_six_ba_statuses(): void
    {
        $this->assertSame(
            ['waiting_start', 'studying', 'deferred', 'summer_break', 'completed', 'dropped'],
            array_keys(Student::STATUSES)
        );
        $this->assertSame('Chờ khai giảng', (new Student(['status' => 'waiting_start']))->status_label);
        $this->assertSame('Nghỉ hè', (new Student(['status' => 'summer_break']))->status_label);

        $student = Student::create([
            'code' => 'HV-STATUS', 'name' => 'Học viên trạng thái', 'phone' => '0900111222',
            'branch_id' => $this->branchHanoi->id, 'status' => 'waiting_start',
        ]);

        foreach (['trial', 'graduated', 'blacklist', 'transferred'] as $invalid) {
            $this->actingAs($this->academicOfficer)->put(route('students.status.update', $student->id), ['status' => $invalid])
                ->assertSessionHasErrors('status');
        }
        $this->actingAs($this->academicOfficer)->put(route('students.status.update', $student->id), ['status' => 'summer_break'])
            ->assertRedirect();
        $this->assertSame('summer_break', $student->fresh()->status);

        $this->actingAs($this->academicOfficer)->get(route('students.index'))
            ->assertOk()->assertSee('Chờ khai giảng')->assertSee('Hoàn thành khóa học')->assertDontSee('Học thử');
    }
}
