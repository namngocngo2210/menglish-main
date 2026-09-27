<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\StaffReport;
use App\Models\Student;
use App\Models\User;
use App\Support\ClassLifecycle;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Luồng Lớp học mới: menu 3 mục (Danh sách lớp · Lịch học & điểm danh · Báo cáo & sự vụ), Trang lớp với vòng đời +
 * tab con, sự vụ gắn lớp. Các màn cũ (Hồ sơ lớp, Sơ đồ khối, Danh sách chi tiết, Chi tiết học thuật) chuyển hướng.
 */
class ClassFlowRedesignTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở Test', 'code' => 'TS', 'is_active' => true]);
        $this->admin = $this->makeUser('admin');
        $this->course = Course::create(['name' => 'Khóa Test', 'code' => 'KT', 'total_lessons' => 24, 'tuition_fee' => 1000000, 'is_active' => true]);
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function makeClass(array $attributes = []): ClassModel
    {
        static $n = 0;
        $n++;

        return ClassModel::create(array_merge([
            'name' => "Lớp Luồng {$n}", 'code' => "LL-{$n}", 'course_id' => $this->course->id,
            'branch_id' => $this->branch->id, 'max_capacity' => 12, 'min_students' => 3, 'status' => 'active',
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2),
        ], $attributes));
    }

    public function test_class_menu_has_three_entries(): void
    {
        $response = $this->actingAs($this->admin)->get(route('classes.index'))->assertOk();

        foreach (['Danh sách lớp', 'Lịch học &amp; điểm danh', 'Báo cáo &amp; sự vụ'] as $label) {
            $response->assertSee($label, false);
        }
        foreach (['Sơ đồ khối', 'Danh sách lớp chi tiết', 'Nhật ký sự vụ lớp'] as $old) {
            $response->assertDontSee($old);
        }
    }

    public function test_old_class_screens_redirect_to_new_flow(): void
    {
        $class = $this->makeClass();

        $this->actingAs($this->admin)->get(route('classes.profile'))->assertRedirect(route('classes.index'));
        $this->actingAs($this->admin)->get(route('classes.profile', $class->id))->assertRedirect(route('classes.show', $class->id));
        $this->actingAs($this->admin)->get(route('classes.academic-detail', $class->id))
            ->assertRedirect(route('classes.show', ['id' => $class->id, 'tab' => 'academic']));
        $this->actingAs($this->admin)->get(route('classes.academic-overview', ['branch' => $this->branch->id]))
            ->assertRedirect(route('classes.index', ['branch_id' => $this->branch->id]));
        $this->actingAs($this->admin)->get(route('classes.academic-list', ['level' => 'B1', 'search' => 'LL']))
            ->assertRedirect(route('classes.index', ['search' => 'LL', 'level' => 'B1']));
    }

    public function test_class_list_filters_by_status_chip_and_shows_next_action(): void
    {
        $this->makeClass(['name' => 'Lớp Đang Chạy', 'program' => 'Kids']);
        $this->makeClass(['name' => 'Lớp Chờ Lịch', 'status' => 'pending_schedule', 'program' => 'Kids']);
        $this->makeClass(['name' => 'Lớp Đã Hủy', 'status' => 'cancelled']);

        $this->actingAs($this->admin)->get(route('classes.index'))
            ->assertOk()
            ->assertSee('Lớp Đang Chạy')->assertSee('Lớp Chờ Lịch')->assertDontSee('Lớp Đã Hủy')
            ->assertSee('Cấu hình lịch')          // việc tiếp theo của lớp chờ lịch
            ->assertSee('Lọc theo chương trình', false);

        $this->actingAs($this->admin)->get(route('classes.index', ['status' => 'pending_schedule']))
            ->assertOk()->assertSee('Lớp Chờ Lịch')->assertDontSee('Lớp Đang Chạy');

        $this->actingAs($this->admin)->get(route('classes.index', ['status' => 'cancelled']))
            ->assertOk()->assertSee('Lớp Đã Hủy');
    }

    public function test_lifecycle_marks_current_step_from_status_and_seats(): void
    {
        $pending = $this->makeClass(['status' => 'pending_schedule']);
        $steps = collect(ClassLifecycle::steps($pending, $pending->seatSummary()))->pluck('state', 'key');
        $this->assertSame('done', $steps['created']);
        $this->assertSame('current', $steps['schedule']);
        $this->assertSame('todo', $steps['launch']);

        $upcoming = $this->makeClass(['status' => 'upcoming', 'start_date' => now()->addWeek()]);
        $steps = collect(ClassLifecycle::steps($upcoming, $upcoming->seatSummary()))->pluck('state', 'key');
        $this->assertSame('current', $steps['students']); // 0/3 ngưỡng

        $active = $this->makeClass();
        $steps = collect(ClassLifecycle::steps($active, $active->seatSummary()))->pluck('state', 'key');
        $this->assertSame('done', $steps['launch']);
        $this->assertSame('current', $steps['running']);
    }

    public function test_class_page_tabs_render_real_data(): void
    {
        $class = $this->makeClass(['name' => 'Lớp Trang Lớp']);
        Student::create(['code' => 'HV-TL-1', 'name' => 'Học viên Trang Lớp', 'phone' => '0911222333', 'branch_id' => $this->branch->id, 'current_class_id' => $class->id, 'status' => 'studying']);
        ClassSession::create(['class_id' => $class->id, 'branch_id' => $this->branch->id, 'date' => today()->subDay(), 'start_time' => '18:00', 'end_time' => '19:30', 'status' => 'scheduled']);
        ClassSession::create(['class_id' => $class->id, 'branch_id' => $this->branch->id, 'date' => today()->addDays(2), 'start_time' => '18:00', 'end_time' => '19:30', 'status' => 'scheduled']);

        $this->actingAs($this->admin)->get(route('classes.show', $class->id))
            ->assertOk()->assertSee('Lớp Trang Lớp')->assertSee('Vòng đời lớp')->assertSee('Buổi kế tiếp')
            ->assertSee(today()->addDays(2)->format('d/m/Y'));

        $this->actingAs($this->admin)->get(route('classes.show', ['id' => $class->id, 'tab' => 'students']))
            ->assertOk()->assertSee('Học viên Trang Lớp');

        $this->actingAs($this->admin)->get(route('classes.show', ['id' => $class->id, 'tab' => 'schedule']))
            ->assertOk()->assertSee('Sắp diễn ra')->assertSee('Đã diễn ra')
            ->assertSee(route('tasks.schedule-config', ['class_id' => $class->id]), false);

        $this->actingAs($this->admin)->get(route('classes.show', ['id' => $class->id, 'tab' => 'attendance']))
            ->assertOk()->assertSee('Chưa điểm danh')->assertSee(today()->subDay()->format('d/m/Y'));
    }

    public function test_incident_logged_on_class_page_shows_in_class_tab_and_dashboard(): void
    {
        $class = $this->makeClass();
        $other = $this->makeClass();

        $this->actingAs($this->admin)->post(route('reports.journal.store'), [
            'title' => 'Phụ huynh phản ánh phòng nóng', 'severity' => 'urgent', 'class_id' => $class->id,
        ])->assertSessionHasNoErrors();
        StaffReport::create(['user_id' => $this->admin->id, 'type' => 'journal', 'title' => 'Sự vụ lớp khác', 'severity' => 'normal', 'report_date' => today(), 'status' => 'open', 'class_id' => $other->id]);

        $this->assertSame($class->id, StaffReport::where('title', 'Phụ huynh phản ánh phòng nóng')->value('class_id'));

        $this->actingAs($this->admin)->get(route('classes.show', ['id' => $class->id, 'tab' => 'incidents']))
            ->assertOk()->assertSee('Phụ huynh phản ánh phòng nóng')->assertDontSee('Sự vụ lớp khác');

        $this->actingAs($this->admin)->get(route('academic.dashboards.incidents'))
            ->assertOk()->assertSee('Phụ huynh phản ánh phòng nóng')->assertSee('Sự vụ lớp khác');

        $this->actingAs($this->admin)->get(route('academic.dashboards.incidents', ['class_id' => $class->id]))
            ->assertOk()->assertSee('Phụ huynh phản ánh phòng nóng')->assertDontSee('Sự vụ lớp khác');
    }

    public function test_incident_cannot_be_attached_to_class_outside_scope(): void
    {
        $otherTeacher = $this->makeUser('teacher');
        $class = $this->makeClass(['teacher_id' => $otherTeacher->id]);
        $teacher = $this->makeUser('teacher');

        $this->actingAs($teacher)->post(route('reports.journal.store'), [
            'title' => 'Sự vụ ngoài phạm vi', 'severity' => 'normal', 'class_id' => $class->id,
        ])->assertForbidden();
        $this->assertDatabaseMissing('staff_reports', ['title' => 'Sự vụ ngoài phạm vi']);
    }
}
