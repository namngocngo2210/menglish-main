<?php

namespace Tests\Feature;

use App\Models\AcademicRecord;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassScheduleConfig;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\Holiday;
use App\Models\Homework;
use App\Models\HrDailyDemand;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\SyllabusCurriculum;
use App\Models\User;
use App\Models\WorkTask;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 2 — màn hình Lịch & vận hành: Dashboard lớp, TKB, Cấu hình trình độ, Portal trợ giảng, Bảng KPI.
 */
class Phase2OperationsScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $foreignTeacher;

    private User $assistant;

    private Branch $branch;

    private Branch $otherBranch;

    private Course $course;

    private ClassModel $classModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-07 09:00:00')); // Thứ 4

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-OPS', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'Đống Đa', 'code' => 'DD-OPS', 'is_active' => true]);
        $this->admin = $this->user('admin', 'Quản trị viên');
        $this->teacher = $this->user('teacher', 'Nguyễn Văn Giáo');
        $this->foreignTeacher = $this->user('teacher', 'John Smith GVNN');
        $this->assistant = $this->user('assistant', 'Trần Thị Trợ');

        $this->course = Course::create(['name' => 'IELTS OPS', 'code' => 'IELTS-OPS', 'total_lessons' => 24, 'is_active' => true]);
        $this->classModel = ClassModel::create([
            'name' => 'IELTS Foundation OPS', 'code' => 'OPS-01', 'course_id' => $this->course->id,
            'program' => $this->course->name, 'level' => 'B1', 'branch_id' => $this->branch->id,
            'teacher_id' => $this->teacher->id, 'foreign_teacher_id' => $this->foreignTeacher->id,
            'assistant_id' => $this->assistant->id, 'room' => 'Phòng 301', 'status' => 'active', 'max_capacity' => 15,
            'start_date' => '2026-09-01', 'end_date' => '2026-12-31',
        ]);
    }

    private function user(string $role, string $name, ?int $branchId = null): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true, 'branch_id' => $branchId ?? $this->branch->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeSession(ClassModel $class, string $date, array $overrides = []): ClassSession
    {
        return ClassSession::create($overrides + [
            'class_id' => $class->id, 'branch_id' => $class->branch_id, 'date' => $date,
            'shift_name' => 'Slot 1', 'start_time' => '17:45', 'end_time' => '19:15', 'room' => $class->room,
            'teacher_id' => $class->teacher_id, 'foreign_teacher_id' => $class->foreign_teacher_id,
            'assistant_id' => $class->assistant_id, 'status' => 'scheduled',
        ]);
    }

    private function student(string $code, ?int $classId, string $status = 'studying'): Student
    {
        return Student::create(['name' => "HV {$code}", 'code' => $code, 'phone' => '09'.str_pad((string) crc32($code) % 100000000, 8, '0'),
            'branch_id' => $this->branch->id, 'current_class_id' => $classId, 'status' => $status]);
    }

    // ── 3. Dashboard lớp học ──────────────────────────────────────────────

    public function test_class_dashboard_lists_real_sessions_for_selected_day_and_branch(): void
    {
        $today = $this->makeSession($this->classModel, '2026-10-07');
        $student = $this->student('OPS-HV1', $this->classModel->id);
        StudentAttendance::create(['class_id' => $this->classModel->id, 'class_session_id' => $today->id, 'student_id' => $student->id,
            'user_id' => $this->teacher->id, 'session_date' => '2026-10-07', 'status' => 'present']);

        $otherClass = ClassModel::create(['name' => 'TOEIC Đống Đa', 'code' => 'OPS-DD', 'branch_id' => $this->otherBranch->id,
            'room' => 'D-201', 'status' => 'active', 'max_capacity' => 20]);
        $this->makeSession($otherClass, '2026-10-07', ['start_time' => '08:00', 'end_time' => '09:30']);
        $idle = ClassModel::create(['name' => 'Lớp không có buổi', 'code' => 'OPS-IDLE', 'branch_id' => $this->branch->id, 'status' => 'active']);

        $response = $this->actingAs($this->admin)->get(route('tasks.classes-dashboard', ['date' => '2026-10-07', 'branch_id' => $this->branch->id]));

        $response->assertOk()
            ->assertSee('IELTS Foundation OPS')
            ->assertSee('17:45 - 19:15')
            ->assertSee('Phòng 301')
            ->assertSee('Nguyễn Văn Giáo')
            ->assertSee('John Smith GVNN')
            ->assertSee('Trần Thị Trợ')
            ->assertSee('1/15')
            ->assertSee('Đã điểm danh')
            ->assertSee(route('teacher.attendance', ['classId' => $this->classModel->id, 'session' => $today->id, 'date' => '2026-10-07']))
            ->assertDontSee('TOEIC Đống Đa')
            ->assertDontSee($idle->name)
            ->assertDontSee('John Doe')
            ->assertDontSee('Phòng '.($this->classModel->id + 100));
    }

    public function test_class_dashboard_flags_missing_attendance_and_holiday_cancellations(): void
    {
        $this->makeSession($this->classModel, '2026-10-06');
        $holiday = Holiday::create(['code' => 'OPS-H', 'name' => 'Nghỉ Quốc khánh bù', 'start_date' => '2026-10-08',
            'end_date' => '2026-10-08', 'is_system_wide' => true]);
        $this->makeSession($this->classModel, '2026-10-08', ['status' => 'cancelled', 'holiday_id' => $holiday->id]);

        $this->actingAs($this->admin)->get(route('tasks.classes-dashboard', ['date' => '2026-10-06']))
            ->assertOk()->assertSee('Chưa điểm danh');

        $this->actingAs($this->admin)->get(route('tasks.classes-dashboard', ['date' => '2026-10-08']))
            ->assertOk()->assertSee('Nghỉ lễ')->assertSee('Nghỉ Quốc khánh bù');
    }

    public function test_class_dashboard_scopes_teacher_to_own_sessions(): void
    {
        $this->makeSession($this->classModel, '2026-10-07');
        $otherTeacher = $this->user('teacher', 'Giáo viên khác');
        $foreignClass = ClassModel::create(['name' => 'Lớp người khác', 'code' => 'OPS-X', 'branch_id' => $this->branch->id,
            'teacher_id' => $otherTeacher->id, 'room' => 'P999', 'status' => 'active']);
        $this->makeSession($foreignClass, '2026-10-07', ['start_time' => '08:00', 'end_time' => '09:00']);

        $this->actingAs($this->foreignTeacher)->get(route('tasks.classes-dashboard'))
            ->assertOk()
            ->assertSee('IELTS Foundation OPS')
            ->assertDontSee('Lớp người khác');
    }

    public function test_class_dashboard_week_matrix_is_built_from_sessions(): void
    {
        $this->makeSession($this->classModel, '2026-10-05');
        $this->makeSession($this->classModel, '2026-10-09', ['start_time' => '08:00', 'end_time' => '09:30', 'room' => 'Phòng 105']);
        $this->makeSession($this->classModel, '2026-10-19'); // tuần khác

        $response = $this->actingAs($this->admin)->get(route('tasks.classes-dashboard', ['tab' => 'week', 'week' => '2026-W41']));

        $response->assertOk()
            ->assertSee('08:00 - 09:30')
            ->assertSee('17:45 - 19:15')
            ->assertSee('Phòng 105')
            ->assertSee('05/10')
            ->assertDontSee('10:00 - 12:00')
            ->assertDontSee('Thi thử IELTS');
        $this->assertSame(2, substr_count($response->getContent(), 'OPS-01</p>'));
    }

    // ── 5. TKB ────────────────────────────────────────────────────────────

    public function test_schedule_config_lists_scheduled_classes_for_editing_and_resaves(): void
    {
        ClassScheduleConfig::create(['class_id' => $this->classModel->id, 'academic_year' => '2026 - 2027',
            'slot1_day' => 'Thứ 2', 'slot1_start' => '18:00', 'slot1_end' => '19:30']);
        $this->classModel->update(['schedule_text' => 'Thứ 2 18:00-19:30']);

        $this->actingAs($this->admin)->get(route('tasks.schedule-config', ['class_id' => $this->classModel->id]))
            ->assertOk()
            ->assertSee('Đã có TKB — sửa lịch')
            ->assertSee('value="'.$this->classModel->id.'"', false)
            ->assertDontSee('12 → 15');

        $this->actingAs($this->admin)->post(route('tasks.schedule-config.update'), [
            'class_id' => $this->classModel->id, 'start_date' => '2026-10-07', 'end_date' => '2026-10-20',
            'slot1_day' => 'Thứ 5', 'slot1_start' => '18:00', 'slot1_end' => '19:30', 'slot2_day' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Thứ 5', $this->classModel->scheduleConfig()->first()->slot1_day);
        $this->assertSame(2, ClassSession::where('class_id', $this->classModel->id)->count());
    }

    public function test_schedule_config_scopes_classes_to_teacher(): void
    {
        ClassModel::create(['name' => 'Lớp bí mật', 'code' => 'OPS-S', 'branch_id' => $this->branch->id, 'status' => 'active']);

        $this->actingAs($this->teacher)->get(route('tasks.schedule-config'))
            ->assertOk()
            ->assertSee('IELTS Foundation OPS')
            ->assertDontSee('Lớp bí mật');
    }

    public function test_room_staff_report_uses_real_sessions_and_saves_demand_per_branch(): void
    {
        $this->makeSession($this->classModel, '2026-10-07');
        $second = ClassModel::create(['name' => 'Lớp 2', 'code' => 'OPS-02', 'branch_id' => $this->branch->id,
            'room' => 'Phòng 302', 'assistant_id' => $this->assistant->id, 'status' => 'active']);
        $this->makeSession($second, '2026-10-07', ['start_time' => '08:00', 'end_time' => '09:00']);

        $response = $this->actingAs($this->admin)->get(route('tasks.schedule-config', [
            'report_branch_id' => $this->branch->id, 'report_date' => '2026-10-07',
        ]))->assertOk();
        $report = $response->viewData('report');
        $this->assertSame(2, $report[0]['shifts']);
        $this->assertSame(2, $report[0]['rooms']);
        $this->assertSame(1, $report[0]['assistants']);
        $this->assertSame(0, $report[1]['shifts']);
        $response->assertSee('0 → 2', false);

        $this->actingAs($this->admin)->post(route('tasks.hr-demand.save'), [
            'branch_id' => $this->branch->id, 'demands' => ['2026-10-07' => 3],
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('tasks.hr-demand.save'), [
            'branch_id' => $this->branch->id, 'demands' => ['2026-10-07' => 4],
        ]);

        $demand = HrDailyDemand::sole();
        $this->assertSame(4, $demand->staff_needed);
        $this->assertSame(2, $demand->shift_count);
        $this->assertSame('Thứ 4', $demand->day_of_week);
    }

    // ── 4. Cấu hình trình độ ──────────────────────────────────────────────

    public function test_course_levels_support_group_syllabus_edit_toggle_and_search(): void
    {
        $syllabus = SyllabusCurriculum::create(['code' => 'SYL-K1', 'title' => 'Kids 1', 'version' => 'V2']);
        $this->actingAs($this->admin)->post(route('course-levels.store'), [
            'code' => 'KID-BEG-01', 'name' => 'Kids Beginner 1', 'level_group' => 'kids', 'target' => 'Starters',
            'lessons_count' => 24, 'syllabus_curriculum_id' => $syllabus->id,
        ])->assertSessionHasNoErrors();
        CourseLevel::create(['code' => 'IELT-FOU', 'name' => 'IELTS Foundation', 'level_group' => 'IELTS', 'target' => '4.5', 'lessons_count' => 30, 'is_active' => true]);

        $level = CourseLevel::where('code', 'KID-BEG-01')->firstOrFail();
        $this->assertSame('KIDS', $level->level_group);
        $this->assertSame($syllabus->id, $level->syllabus_curriculum_id);

        $this->actingAs($this->admin)->get(route('course-levels.index', ['search' => 'Kids']))
            ->assertOk()->assertSee('Kids Beginner 1')->assertSee('SYL-K1')->assertDontSee('IELTS Foundation</div>', false);

        $this->actingAs($this->admin)->put(route('course-levels.update', $level->id), [
            'name' => 'Kids Beginner 1A', 'level_group' => 'KIDS', 'target' => 'Starters', 'lessons_count' => 20,
            'syllabus_curriculum_id' => '', 'is_active' => '1',
        ])->assertSessionHasNoErrors();
        $level->refresh();
        $this->assertSame('Kids Beginner 1A', $level->name);
        $this->assertNull($level->syllabus_curriculum_id);

        $this->actingAs($this->admin)->put(route('course-levels.update', $level->id), ['toggle_status' => 1]);
        $this->assertFalse($level->fresh()->is_active);
        $this->actingAs($this->admin)->get(route('course-levels.index', ['status' => 'inactive']))
            ->assertSee('Kids Beginner 1A')->assertSee('Ngừng hoạt động');
    }

    public function test_course_level_in_use_cannot_be_deleted(): void
    {
        $used = CourseLevel::create(['code' => 'B1', 'name' => 'Intermediate', 'target' => 'B1', 'lessons_count' => 24, 'is_active' => true]);
        $free = CourseLevel::create(['code' => 'C2', 'name' => 'Proficiency', 'target' => 'C2', 'lessons_count' => 24, 'is_active' => true]);

        $this->actingAs($this->admin)->delete(route('course-levels.destroy', $used->id))->assertSessionHasErrors('level');
        $this->assertModelExists($used);

        $this->actingAs($this->admin)->delete(route('course-levels.destroy', $free->id))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($free);
    }

    // ── 7. Portal trợ giảng ───────────────────────────────────────────────

    private function task(User $assignee, string $title, string $dueDate, string $category = 'before', string $status = 'new'): WorkTask
    {
        return WorkTask::create(['title' => $title, 'creator_id' => $this->admin->id, 'assignee_id' => $assignee->id,
            'time_slot_category' => $category, 'task_type' => 'one_time', 'due_date' => $dueDate, 'due_time' => '14:00', 'status' => $status]);
    }

    public function test_ta_portal_shows_only_tasks_of_selected_day(): void
    {
        $this->task($this->assistant, 'Việc hôm nay', '2026-10-07');
        $this->task($this->assistant, 'Việc hôm qua', '2026-10-06');
        $this->task($this->assistant, 'Việc ngày mai', '2026-10-08', 'after');
        $this->makeSession($this->classModel, '2026-10-07');

        $this->actingAs($this->assistant)->get(route('portal.ta-tasks'))
            ->assertOk()
            ->assertSee('Nhiệm vụ hôm nay')
            ->assertSee('Việc hôm nay')
            ->assertDontSee('Việc hôm qua')
            ->assertDontSee('Việc ngày mai')
            ->assertSee('Còn <strong>1</strong> nhiệm vụ', false)
            ->assertSee('IELTS Foundation OPS')
            ->assertSee('Điều hướng portal trợ giảng');

        $this->actingAs($this->assistant)->get(route('portal.ta-tasks', ['date' => '2026-10-08']))
            ->assertOk()->assertSee('Việc ngày mai')->assertDontSee('Việc hôm nay');
    }

    public function test_ta_portal_admin_picks_assistant_and_ta_cannot_view_others(): void
    {
        $otherTa = $this->user('assistant', 'Trợ giảng Hai');
        $this->task($this->assistant, 'Việc của Trợ', '2026-10-07');
        $this->task($otherTa, 'Việc của Hai', '2026-10-07');
        // Tài khoản TA mẫu trước đây bị gán cứng khi admin mở portal.
        $legacy = User::factory()->create(['email' => 'ta.tuan@menglish.edu.vn', 'is_active' => true]);
        $this->task($legacy, 'Việc của tài khoản mẫu', '2026-10-07');

        $this->actingAs($this->admin)->get(route('portal.ta-tasks', ['ta_id' => $otherTa->id]))
            ->assertOk()->assertSee('Việc của Hai')->assertDontSee('Việc của Trợ')->assertDontSee('Việc của tài khoản mẫu')
            ->assertSee('Chọn trợ giảng');

        $this->actingAs($this->assistant)->get(route('portal.ta-tasks', ['ta_id' => $otherTa->id]))
            ->assertOk()->assertSee('Việc của Trợ')->assertDontSee('Việc của Hai')->assertDontSee('Chọn trợ giảng');
    }

    // ── 8. Bảng KPI tự động ───────────────────────────────────────────────

    public function test_kpi_board_computes_rates_from_real_data_for_selected_month(): void
    {
        $session = $this->makeSession($this->classModel, '2026-10-05');
        $students = collect(['K1', 'K2', 'K3', 'K4'])->map(fn ($code) => $this->student("OPS-{$code}", $this->classModel->id));
        $this->student('OPS-K5', $this->classModel->id, 'dropped');
        foreach ($students as $i => $student) {
            StudentAttendance::create(['class_id' => $this->classModel->id, 'class_session_id' => $session->id, 'student_id' => $student->id,
                'user_id' => $this->teacher->id, 'session_date' => '2026-10-05', 'status' => $i === 0 ? 'absent' : 'present']);
        }
        // Tháng trước: không tính vào kỳ 10/2026.
        StudentAttendance::create(['class_id' => $this->classModel->id, 'student_id' => $students[0]->id,
            'user_id' => $this->teacher->id, 'session_date' => '2026-09-28', 'status' => 'absent']);

        Homework::create(['class_id' => $this->classModel->id, 'user_id' => $this->teacher->id, 'title' => 'Unit 1', 'due_date' => '2026-10-10']);
        foreach ($students->take(2) as $student) {
            AcademicRecord::create(['screen_key' => '04_Cong_Phu_Huynh_Hoc_Sinh/03_hoc_tap_cua_toi_nop_bai_tap', 'module' => 'student_portal',
                'record_code' => 'SUB-'.$student->id, 'title' => 'Bài nộp', 'status' => 'submitted', 'data' => ['student_id' => (string) $student->id]]);
        }

        $this->task($this->teacher, 'Việc xong', '2026-10-02', 'before', 'completed');
        $this->task($this->teacher, 'Việc chưa xong', '2026-10-03');
        $this->task($this->teacher, 'Việc tháng khác', '2026-09-03');

        $response = $this->actingAs($this->admin)->get(route('tasks.kpi-dashboard', ['month' => '2026-10']))->assertOk();
        $row = $response->viewData('kpiData')->firstWhere('user.id', $this->teacher->id);

        $this->assertSame(80.0, $row['retention']);   // 4/5 chưa thôi học
        $this->assertSame(75.0, $row['attendance']);  // 3/4 lượt trong tháng 10
        $this->assertSame(50.0, $row['homework']);    // 2 bài nộp / (1 bài × 4 HV)
        $this->assertSame(50.0, $row['tasks']);       // 1/2 việc có hạn trong tháng
        $response->assertSee('75,0%')->assertDontSee('96.8%')->assertSee('01/10/2026 - 31/10/2026');

        // Kỳ không có dữ liệu → "Chưa có dữ liệu", không có số giả.
        $empty = $this->actingAs($this->admin)->get(route('tasks.kpi-dashboard', ['month' => '2026-06', 'user_id' => $this->assistant->id]))->assertOk();
        $assistantRow = $empty->viewData('kpiData')->sole();
        $this->assertNull($assistantRow['attendance']);
        $this->assertNull($assistantRow['tasks']);
        $empty->assertSee('Chưa có dữ liệu');
    }

    public function test_kpi_board_has_no_email_pattern_fallback_staff(): void
    {
        User::factory()->create(['name' => 'Kế toán tên teacher', 'email' => 'teacher.fake@menglish.edu.vn', 'is_active' => true]);

        $response = $this->actingAs($this->admin)->get(route('tasks.kpi-dashboard'))->assertOk();
        $names = $response->viewData('kpiData')->pluck('user.name');
        $this->assertNotContains('Kế toán tên teacher', $names);
        $this->assertContains('Trần Thị Trợ', $names);
    }
}
