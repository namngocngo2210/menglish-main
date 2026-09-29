<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected CourseLevel $level;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $this->admin = User::create([
            'name' => 'Admin Course Manager',
            'email' => 'admin.course@menglish.edu.vn',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $this->admin->syncRoles(['admin']);

        $this->level = CourseLevel::create([
            'code' => 'B2',
            'name' => 'Upper-Intermediate',
            'target' => 'IELTS 6.5',
            'duration' => '12 tuần',
            'lessons_count' => 24,
            'is_active' => true,
        ]);
    }

    public function test_can_view_courses_and_prices_list(): void
    {
        Course::create([
            'code' => 'IE-65',
            'name' => 'IELTS 6.5 Intensive',
            'course_level_id' => $this->level->id,
            'tuition_fee' => 12500000,
            'total_lessons' => 24,
            'description' => 'Khóa IELTS 6.5',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->get(route('courses.index'));
        $response->assertStatus(200);
        $response->assertSee('IELTS 6.5 Intensive');
        $response->assertSee('12.500.000 đ');
        $response->assertSee('IE-65');
    }

    public function test_can_create_new_course_with_tuition_fee(): void
    {
        $response = $this->actingAs($this->admin)->post(route('courses.store'), [
            'name' => 'Khóa Luyện Thi TOEIC 750+',
            'course_level_id' => $this->level->id,
            'tuition_fee' => 8500000,
            'total_lessons' => 20,
            'description' => 'Cam kết chuẩn đầu ra 750+',
            'is_active' => 1,
        ]);

        $response->assertRedirect(route('courses.index'));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('courses', [
            'code' => 'CS0001',
            'name' => 'Khóa Luyện Thi TOEIC 750+',
            'tuition_fee' => 8500000,
            'total_lessons' => 20,
        ]);
    }

    public function test_course_code_is_generated_sequentially_and_never_reused(): void
    {
        // Mã cũ nhập tay (khác định dạng) không ảnh hưởng dãy số; mã CS của khóa đã xóa mềm không bị cấp lại.
        Course::create(['code' => 'IE-65', 'name' => 'Khóa cũ', 'is_active' => true]);
        Course::create(['code' => 'CS0007', 'name' => 'Khóa đã xóa', 'is_active' => true])->delete();

        $this->actingAs($this->admin)->post(route('courses.store'), [
            'code' => 'HACK-1', 'name' => 'Khóa A', 'tuition_fee' => 1000000, 'total_lessons' => 10, 'is_active' => 1,
        ])->assertRedirect(route('courses.index'));
        $this->actingAs($this->admin)->post(route('courses.store'), [
            'name' => 'Khóa B', 'tuition_fee' => 1000000, 'total_lessons' => 10, 'is_active' => 1,
        ])->assertRedirect(route('courses.index'));

        $this->assertSame('CS0008', Course::where('name', 'Khóa A')->value('code'));
        $this->assertSame('CS0009', Course::where('name', 'Khóa B')->value('code'));
        $this->assertDatabaseMissing('courses', ['code' => 'HACK-1']);
    }

    public function test_course_code_cannot_be_changed_on_update(): void
    {
        $course = Course::create(['code' => 'GT-B1', 'name' => 'Giao tiếp B1', 'tuition_fee' => 9000000, 'total_lessons' => 24, 'is_active' => true]);

        $this->actingAs($this->admin)->put(route('courses.update', $course->id), [
            'code' => 'DOI-MA', 'name' => 'Giao tiếp B1', 'tuition_fee' => 9000000, 'total_lessons' => 24, 'is_active' => 1,
        ])->assertRedirect(route('courses.index'));

        $this->assertSame('GT-B1', $course->fresh()->code);
    }

    public function test_can_update_course_tuition_fee_and_info(): void
    {
        $course = Course::create([
            'code' => 'GT-B1',
            'name' => 'Tiếng Anh Giao Tiếp B1',
            'course_level_id' => $this->level->id,
            'tuition_fee' => 9000000,
            'total_lessons' => 24,
            'is_active' => true,
        ]);

        // Cập nhật tăng giá học phí lên 10.500.000đ
        $response = $this->actingAs($this->admin)->put(route('courses.update', $course->id), [
            'code' => 'GT-B1',
            'name' => 'Tiếng Anh Giao Tiếp Pro B1',
            'course_level_id' => $this->level->id,
            'tuition_fee' => 10500000,
            'total_lessons' => 24,
            'is_active' => 1,
        ]);

        $response->assertRedirect(route('courses.index'));

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'name' => 'Tiếng Anh Giao Tiếp Pro B1',
            'tuition_fee' => 10500000,
        ]);
    }

    public function test_can_toggle_course_active_status(): void
    {
        $course = Course::create([
            'code' => 'TEST-OFF',
            'name' => 'Khóa Tạm Dừng',
            'course_level_id' => $this->level->id,
            'tuition_fee' => 5000000,
            'total_lessons' => 10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->patch(route('courses.toggle', $course->id));
        $response->assertRedirect();

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'is_active' => false,
        ]);
    }

    public function test_cannot_delete_course_with_active_classes(): void
    {
        $branch = Branch::create([
            'code' => 'HN-CG',
            'name' => 'Cầu Giấy',
            'is_active' => true,
        ]);

        $course = Course::create([
            'code' => 'IE-LOCKED',
            'name' => 'Khóa Có Lớp Đang Học',
            'tuition_fee' => 15000000,
            'total_lessons' => 30,
            'is_active' => true,
        ]);

        ClassModel::create([
            'code' => 'CLS-01',
            'name' => 'Lớp Đang Chạy',
            'course_id' => $course->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)->delete(route('courses.destroy', $course->id));
        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
        ]);
    }
}
