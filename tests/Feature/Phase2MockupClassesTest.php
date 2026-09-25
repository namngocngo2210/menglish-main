<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\Student;
use App\Models\SyllabusCurriculum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 2 — đối chiếu mockup nhóm Lớp học: Cấu hình trình độ, TKB, Dashboard lớp, Ngày nghỉ,
 * Hồ sơ học sinh (danh sách / chi tiết / phân quyền), Cổng giáo viên.
 */
class Phase2MockupClassesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $assistant;

    private Branch $branch;

    private Course $course;

    private ClassModel $classModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-07 09:00:00')); // Thứ 4

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-MK2', 'is_active' => true]);
        $this->admin = $this->user('admin', 'Quản trị viên');
        $this->teacher = $this->user('teacher', 'Nguyễn Văn Giáo');
        $this->assistant = $this->user('assistant', 'Trần Thị Trợ');

        $this->course = Course::create(['name' => 'Kids MK2', 'code' => 'KIDS-MK2', 'total_lessons' => 24, 'is_active' => true]);
        $this->classModel = ClassModel::create([
            'name' => 'Kids Explorer MK2', 'code' => 'MK2-01', 'course_id' => $this->course->id,
            'program' => $this->course->name, 'level' => 'KID-BEG-01', 'branch_id' => $this->branch->id,
            'teacher_id' => $this->teacher->id, 'assistant_id' => $this->assistant->id,
            'room' => 'Phòng 204', 'status' => 'active', 'max_capacity' => 12,
            'start_date' => '2026-09-01', 'end_date' => '2026-12-31', 'schedule_text' => 'Thứ 4 08:00-09:30',
        ]);
    }

    private function user(string $role, string $name, ?int $branchId = null): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true, 'branch_id' => $branchId ?? $this->branch->id]);
        $user->assignRole($role);

        return $user;
    }

    private function student(string $name, array $attributes = []): Student
    {
        static $n = 0;
        $n++;

        return Student::create($attributes + [
            'code' => 'HV-MK2-'.$n, 'name' => $name, 'phone' => '09120000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'branch_id' => $this->branch->id, 'current_class_id' => $this->classModel->id, 'status' => 'studying',
        ]);
    }

    private function makeSession(string $date, string $start = '08:00', string $end = '09:30', array $attributes = []): ClassSession
    {
        return ClassSession::create($attributes + [
            'class_id' => $this->classModel->id, 'branch_id' => $this->branch->id, 'date' => $date,
            'start_time' => $start, 'end_time' => $end, 'room' => 'Phòng 204', 'type' => ClassSession::TYPE_REGULAR,
            'teacher_id' => $this->teacher->id, 'assistant_id' => $this->assistant->id, 'status' => 'scheduled',
        ]);
    }

    // ── 1. Cấu hình trình độ ───────────────────────────────────────────────

    public function test_course_levels_screen_matches_mockup_with_panel_description_reorder_and_delete_warning(): void
    {
        $syllabus = SyllabusCurriculum::create(['code' => 'SYL-K1', 'title' => 'Kids Early Start', 'version' => 'V2']);
        $this->actingAs($this->admin)->post(route('course-levels.store'), [
            'code' => 'KID-BEG-01', 'name' => 'Kids Beginner 1', 'description' => 'Làm quen phonics', 'level_group' => 'kids',
            'target' => 'Starters', 'lessons_count' => 24, 'syllabus_curriculum_id' => $syllabus->id, 'is_active' => '1',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('course-levels.store'), [
            'code' => 'IELT-FOU-00', 'name' => 'IELTS Foundation', 'level_group' => 'IELTS', 'target' => '4.5',
            'lessons_count' => 30, 'is_active' => '0',
        ])->assertSessionHasNoErrors();
        $kids = CourseLevel::where('code', 'KID-BEG-01')->firstOrFail();
        $ielts = CourseLevel::where('code', 'IELT-FOU-00')->firstOrFail();
        $this->assertSame('Làm quen phonics', $kids->description);
        $this->assertFalse($ielts->is_active, 'Công tắc "Trạng thái hoạt động" khi tạo mới được tôn trọng.');
        $this->student('Học sinh Tham Chiếu');

        $this->actingAs($this->admin)->get(route('course-levels.index'))->assertOk()
            ->assertSeeInOrder(['Kids Beginner 1', 'IELTS Foundation'])
            ->assertSee('Cấu hình Trình độ &amp; Syllabus', false)
            ->assertSee('Tìm kiếm trình độ...')
            ->assertSee('Thêm/Sửa Trình độ đào tạo')
            ->assertSee('Thiết lập Syllabus')->assertSee('Gắn Syllabus mới')
            ->assertSee('Trạng thái hoạt động')->assertSee('Mô tả')
            ->assertSee('drag_indicator')
            ->assertSee('Không thể xóa')
            ->assertSee('Trình độ này đang có 1 lớp học và 1 học sinh tham chiếu', false)
            ->assertSee('Làm quen phonics');

        // Kéo thả: IELTS lên trước Kids.
        $this->actingAs($this->admin)->postJson(route('course-levels.reorder'), ['ids' => [$ielts->id, $kids->id]])
            ->assertOk()->assertJson(['message' => 'Đã lưu thứ tự trình độ.']);
        $this->actingAs($this->admin)->get(route('course-levels.index'))->assertSeeInOrder(['IELTS Foundation', 'Kids Beginner 1']);

        // Giáo viên không có quyền sửa → không sắp xếp được.
        $this->actingAs($this->teacher)->postJson(route('course-levels.reorder'), ['ids' => [$kids->id, $ielts->id]])->assertForbidden();
    }

    // ── 2. TKB ─────────────────────────────────────────────────────────────

    public function test_schedule_config_matches_mockup_with_year_select_server_search_and_conflict_banner(): void
    {
        $other = ClassModel::create([
            'name' => 'TOEIC Fast MK2', 'code' => 'MK2-02', 'branch_id' => $this->branch->id, 'teacher_id' => $this->teacher->id,
            'room' => 'Phòng 204', 'status' => 'active', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31',
        ]);

        $this->actingAs($this->admin)->get(route('tasks.schedule-config'))->assertOk()
            ->assertSee('TKB — Quản lý lớp học')->assertSee('Xuất Excel')->assertSee('Tạo lớp mới')
            ->assertSee('Năm học 2026 - 2027')
            ->assertSee('Slot 1')->assertSee('Slot 2')->assertSee('Hủy thay đổi')
            ->assertSee('Danh sách lớp hiện tại')->assertSee('GV: Nguyễn Văn Giáo')
            ->assertSee('Báo cáo phòng / nhân sự')->assertSee('Giá trị tự động tính toán từ số ca')
            ->assertSee('Lưu báo cáo nhân sự');

        // Tìm lớp chạy phía server.
        $this->actingAs($this->admin)->get(route('tasks.schedule-config', ['class_q' => 'toeic']))->assertOk()
            ->assertSee('name="class_q" value="toeic"', false)
            ->assertSeeInOrder(['Danh sách lớp hiện tại', 'TOEIC Fast MK2'])
            ->assertDontSee('<span class="font-semibold">Kids Explorer MK2</span>', false);

        // Trùng phòng với lớp khác → banner "Cảnh báo xung đột lịch".
        $this->makeSession('2026-10-12', '18:00', '19:30', ['class_id' => $other->id, 'teacher_id' => null, 'assistant_id' => null]);
        $this->actingAs($this->admin)->from(route('tasks.schedule-config'))->post(route('tasks.schedule-config.update'), [
            'class_id' => $this->classModel->id, 'academic_year' => '2026 - 2027', 'start_date' => '2026-10-08', 'end_date' => '2026-10-20',
            'slot1_day' => 'Thứ 2', 'slot1_start' => '18:00', 'slot1_end' => '19:30',
        ])->assertSessionHasErrors('class_id');
        $this->actingAs($this->admin)->withSession(['errors' => session('errors')])->get(route('tasks.schedule-config'))
            ->assertSee('Cảnh báo xung đột lịch')->assertSee('data-testid="schedule-conflict"', false);
    }
}
