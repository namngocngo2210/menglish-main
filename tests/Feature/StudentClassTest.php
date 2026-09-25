<?php

namespace Tests\Feature;

use App\Models\ClassModel;
use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentClassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    }

    public function test_can_create_student_profile(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $response = $this->actingAs($user)->post('/students', [
            'name' => 'Đỗ Hoàng Long',
            'phone' => '0936 999 111',
            'email' => 'hoanglong.do@gmail.com',
            'target' => 'IELTS 7.0',
        ]);

        $response->assertRedirect(route('students.index'));
        $this->assertDatabaseHas('students', [
            'name' => 'Đỗ Hoàng Long',
            'phone' => '0936 999 111',
            'target' => 'IELTS 7.0',
        ]);
    }

    public function test_can_enroll_student_to_class(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $student = Student::create([
            'code' => 'HV-90003',
            'name' => 'Vũ Hải Đăng',
            'phone' => '0977 222 333',
        ]);
        $class = ClassModel::create([
            'code' => 'IE-99',
            'name' => 'Lớp Test K99',
        ]);

        $response = $this->actingAs($user)->post('/students/enrollments', [
            'student_id' => $student->id,
            'class_id' => $class->id,
        ]);

        $response->assertRedirect(route('students.enrollments'));
        $this->assertDatabaseHas('class_enrollments', [
            'student_id' => $student->id,
            'class_id' => $class->id,
        ]);

        $student->refresh();
        $this->assertEquals($class->id, $student->current_class_id);
    }
}
