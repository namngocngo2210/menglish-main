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

    // ── 3. Dashboard lớp học ──────────────────────────────────────────────

    public function test_class_dashboard_matches_mockup_with_attendance_window_extra_filters_and_branch_alert(): void
    {
        $morning = $this->makeSession('2026-10-07', '07:00', '08:30');   // đã học, trong cửa sổ 24h
        $evening = $this->makeSession('2026-10-07', '18:00', '19:30');   // chưa tới giờ
        $old = $this->makeSession('2026-10-05', '07:00', '08:30');       // quá 24h
        $student = $this->student('Học sinh Dashboard');
        \App\Models\StudentAttendance::create(['class_id' => $this->classModel->id, 'class_session_id' => $morning->id,
            'student_id' => $student->id, 'user_id' => $this->teacher->id, 'session_date' => '2026-10-07', 'status' => 'present']);

        $this->actingAs($this->admin)->get(route('tasks.classes-dashboard', ['date' => '2026-10-07']))->assertOk()
            ->assertSee('Quản lý lịch học, điểm danh và chấm công giảng viên')
            ->assertSee('Xuất báo cáo')->assertSee('Thêm lớp học')
            ->assertSee('Theo ngày')->assertSee('Theo tuần')->assertSee('Lọc thêm')
            ->assertSee('Chỉ được chấm công trong vòng 24h sau giờ học')
            ->assertSee('Xem điểm danh')
            ->assertSee('Trợ giảng làm việc hôm nay')->assertSee('Xem tất cả trợ giảng')
            ->assertSee('Hiển thị 2 buổi học của 1 lớp học')
            ->assertDontSee('data-testid="no-branch-alert"', false);

        $this->actingAs($this->admin)->get(route('tasks.classes-dashboard', ['date' => '2026-10-05']))
            ->assertSee('Điểm danh bù');

        // Lọc thêm: chỉ buổi đã điểm danh.
        $this->actingAs($this->admin)->get(route('tasks.classes-dashboard', ['date' => '2026-10-07', 'attendance' => 'done']))
            ->assertSee('Hiển thị 1 buổi học của 1 lớp học')->assertSee('07:00 - 08:30')->assertDontSee('18:00 - 19:30');

        // Tài khoản không gán chi nhánh → cảnh báo như mockup.
        $noBranch = User::factory()->create(['name' => 'Học vụ chưa gán CN', 'is_active' => true, 'branch_id' => null]);
        $noBranch->assignRole('academic_staff');
        $this->actingAs($noBranch)->get(route('tasks.classes-dashboard'))
            ->assertOk()->assertSee('Tài khoản chưa gán chi nhánh, liên hệ Quản trị viên.');
    }

    // ── 4. Ngày nghỉ ───────────────────────────────────────────────────────

    public function test_holidays_screen_is_a_single_page_with_side_form_search_and_auto_code(): void
    {
        $quan7 = Branch::create(['name' => 'Chi nhánh Quận 7', 'code' => 'Q7-MK2', 'is_active' => true]);

        $this->actingAs($this->admin)->get(route('holidays.index'))->assertOk()
            ->assertSee('Lưu ý nghiệp vụ')
            ->assertSee('Danh sách ngày nghỉ')->assertSee('Tìm kiếm ngày nghỉ...')->assertSee('Thêm ngày nghỉ');

        // IX-1: form Thêm mở bằng modal; mở thẳng URL create vẫn là danh sách + form bên phải.
        $this->actingAs($this->admin)->get(route('holidays.create'))->assertOk()
            ->assertSee('Danh sách ngày nghỉ')->assertSee('Thông tin ngày nghỉ')->assertSee('Ví dụ: Tết Trung Thu')
            ->assertSee('Phạm vi áp dụng')->assertSee('Toàn hệ thống (Mặc định)')
            ->assertSee('* Để trống nếu muốn áp dụng cho tất cả chi nhánh.')
            ->assertSee('Hủy bỏ')->assertSee('Lưu thông tin');

        // Không nhập mã, không chọn chi nhánh → mã tự sinh + toàn hệ thống.
        $this->actingAs($this->admin)->post(route('holidays.store'), [
            'name' => 'Tết Nguyên Đán 2027', 'start_date' => '2027-02-05', 'end_date' => '2027-02-11',
        ])->assertRedirect(route('holidays.index'))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('holidays.store'), [
            'name' => 'Giỗ Tổ Hùng Vương', 'start_date' => '2027-04-16', 'end_date' => '2027-04-16', 'branch_ids' => [$quan7->id],
        ])->assertSessionHasNoErrors();

        $tet = \App\Models\Holiday::where('name', 'Tết Nguyên Đán 2027')->firstOrFail();
        $this->assertSame('HOL-2027-001', $tet->code);
        $this->assertTrue($tet->is_system_wide);
        $gioTo = \App\Models\Holiday::where('name', 'Giỗ Tổ Hùng Vương')->firstOrFail();
        $this->assertSame('HOL-2027-002', $gioTo->code);
        $this->assertFalse($gioTo->is_system_wide);

        $this->actingAs($this->admin)->get(route('holidays.index', ['search' => 'Giỗ']))->assertOk()
            ->assertSee('Giỗ Tổ Hùng Vương')->assertSee('Chi nhánh Quận 7')->assertDontSee('Tết Nguyên Đán 2027');

        // Sửa ngay trên trang danh sách (form bên phải điền sẵn).
        $this->actingAs($this->admin)->get(route('holidays.edit', $tet))->assertOk()
            ->assertSee('Sửa ngày nghỉ')->assertSee('value="Tết Nguyên Đán 2027"', false)->assertSee('Danh sách ngày nghỉ');
    }

    // ── 5. Hồ sơ học sinh — danh sách ─────────────────────────────────────

    public function test_student_list_matches_mockup_with_status_chips_contact_column_and_link_class_popup(): void
    {
        $this->student('Nguyễn Nam Anh', ['dob' => '2008-05-12', 'email' => 'namanh@example.com']);
        $this->student('Lê Hồng Minh', ['status' => 'deferred']);
        $this->student('Trần Hoàng Long', ['current_class_id' => null, 'status' => 'waiting_start']);
        $staff = $this->user('academic_staff', 'Học vụ MK2');

        $response = $this->actingAs($staff)->get(route('students.index'))->assertOk()
            ->assertSee('Hồ sơ học sinh')->assertSee('Quản lý và tra cứu thông tin học sinh toàn hệ thống.')
            ->assertSee('Tổng số học sinh')->assertSee('Tìm học sinh hoặc SĐT...')->assertSee('Lọc dữ liệu')
            ->assertSee('Họ tên &amp; Ngày sinh', false)->assertSee('Thông tin liên hệ')->assertSee('Lớp hiện tại')
            ->assertSee('12/05/2008')->assertSee('namanh@example.com')->assertSee('MK2-01')->assertSee('Chưa có lớp')
            ->assertSee('Chi tiết')->assertSee('Liên kết lớp khác')->assertSee('data-testid="list-link-class-form"', false)
            ->assertDontSee('Học thử')->assertDontSee('Blacklist');
        foreach (Student::STATUSES as $key => $label) {
            $response->assertSee('name="statuses[]" value="'.$key.'"', false)->assertSee($label);
        }

        // Chip trạng thái chọn nhiều.
        $this->actingAs($staff)->get(route('students.index', ['statuses' => ['deferred', 'waiting_start']]))
            ->assertSee('Lê Hồng Minh')->assertSee('Trần Hoàng Long')->assertDontSee('Nguyễn Nam Anh');

        // Học thuật không có quyền xếp lớp → không có nút Liên kết lớp khác.
        $this->actingAs($this->user('academic_lead', 'Học thuật MK2'))->get(route('students.index'))->assertOk()->assertDontSee('Liên kết lớp khác');
    }

    // ── 6. Hồ sơ học sinh — chi tiết + phân quyền ─────────────────────────

    public function test_student_detail_matches_mockup_with_lesson_content_status_menu_school_and_roadmap_export(): void
    {
        $curriculum = SyllabusCurriculum::create(['code' => 'SYL-K1', 'title' => 'Kids Early Start']);
        CourseLevel::create(['code' => 'KID-BEG-01', 'name' => 'Kids Beginner 1', 'target' => 'Starters', 'lessons_count' => 24,
            'is_active' => true, 'syllabus_curriculum_id' => $curriculum->id]);
        $unit = \App\Models\SyllabusUnit::create(['curriculum_id' => $curriculum->id, 'stage_id' => $curriculum->stages()->first()->id,
            'unit_number' => 4, 'title' => 'Future Tech & AI']);
        \App\Models\SyllabusLesson::create(['curriculum_id' => $curriculum->id, 'unit_id' => $unit->id, 'session_no' => 1, 'title' => 'Grammar: Will vs Be going to']);

        $this->makeSession('2026-10-05', '17:30', '19:00', ['status' => 'completed']);
        $this->makeSession('2026-10-08', '17:30', '19:00');
        $this->makeSession('2026-10-12', '17:30', '19:00');
        $student = $this->student('Nguyễn Minh Tuấn', ['dob' => '2012-08-15']);
        $staff = $this->user('academic_staff', 'Học vụ MK2');

        $this->actingAs($staff)->get(route('students.show', $student->id))->assertOk()
            ->assertSee('Chi tiết hồ sơ học sinh')->assertSee('15/08/2012 (14 tuổi)')
            ->assertSee('Mã học sinh')->assertSee('Ngày nhập học')
            ->assertSee('Chỉnh sửa thông tin')->assertSee('Trường học')->assertSee('Ghi chú đặc biệt')
            ->assertSee('Trạng thái hiện tại')->assertSee('Đổi trạng thái')->assertSee('Thời gian cập nhật')
            ->assertSee('Lộ trình học tập &amp; Danh sách buổi học', false)
            ->assertSee('Thứ 2, 05/10/2026')->assertSee('Unit 4: Future Tech &amp; AI', false)->assertSee('Grammar: Will vs Be going to')
            ->assertSee('Đã hoàn thành')->assertSee('Sắp diễn ra')->assertSee('Chưa bắt đầu')
            ->assertSee('Lớp học hiện tại')->assertSee('Chi tiết lộ trình')->assertSee('Chuyên cần')->assertSee('Số buổi vắng:')
            ->assertSee('Thông tin học phí')
            ->assertDontSee('>Học thử<', false);

        $this->actingAs($staff)->put(route('students.update', $student->id), [
            'name' => $student->name, 'phone' => $student->phone, 'school' => 'Trường THCS Đoàn Thị Điểm',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Trường THCS Đoàn Thị Điểm', $student->fresh()->school);

        $this->actingAs($staff)->get(route('students.show', ['id' => $student->id, 'export' => 'roadmap', 'format' => 'csv']))
            ->assertOk()->assertDownload();
    }

    public function test_scoped_student_profile_shows_locked_controls_and_hides_modules_without_permission(): void
    {
        $student = $this->student('Học sinh Phân Quyền');

        // Học thuật: xem được lớp/điểm danh, không sửa, không đổi trạng thái, không học phí/liên hệ.
        $this->actingAs($this->user('academic_lead', 'Học thuật MK2'))->get(route('students.scoped', $student->id))->assertOk()
            ->assertSee('Chi tiết hồ sơ học sinh')
            ->assertSee('Bạn không có quyền sửa thông tin này')
            ->assertSee('Quyền xem duy nhất')
            ->assertSee('data-section="academic"', false)
            ->assertDontSee('data-section="tuition"', false)
            ->assertDontSee('data-section="contact"', false)
            ->assertDontSee('data-testid="student-edit-form"', false);

        // Học vụ: sửa + đổi trạng thái được.
        $staff = $this->user('academic_staff', 'Học vụ MK2');
        $this->actingAs($staff)->get(route('students.scoped', $student->id))->assertOk()
            ->assertSee('data-testid="student-edit-form"', false)
            ->assertDontSee('Quyền xem duy nhất');
    }

    // ── 7. Cổng giáo viên ─────────────────────────────────────────────────

    public function test_teacher_home_matches_app_shell_mockup_with_widgets_banner_and_bottom_nav(): void
    {
        $session = $this->makeSession('2026-10-07', '10:00', '11:30');
        $student = $this->student('Học sinh Cần Chú Ý');
        \App\Models\MiniTestScore::create(['class_id' => $this->classModel->id, 'student_id' => $student->id, 'user_id' => $this->teacher->id,
            'name' => 'Unit 3', 'score' => 4.5, 'max_score' => 10, 'test_date' => '2026-10-06']);
        \App\Models\TeacherHourlyRate::create(['user_id' => $this->teacher->id, 'hourly_rate' => 200000, 'effective_from' => '2026-01-01']);
        \App\Models\TeacherTimesheet::create(['user_id' => $this->teacher->id, 'class_id' => $this->classModel->id, 'teaching_date' => '2026-10-05',
            'hours' => 1.5, 'status' => 'approved', 'source' => \App\Models\TeacherTimesheet::SOURCE_CHECKIN]);

        $this->actingAs($this->teacher)->get(route('teacher.home'))->assertOk()
            ->assertSee('Tổng quan hôm nay')
            ->assertSee('Lịch dạy hôm nay — Thứ Tư, 07/10')->assertSee('Bạn có 1 ca dạy trong ngày hôm nay')
            ->assertSee('Ca dạy lúc 10:00 sắp bắt đầu!')->assertSee('Điểm danh ngay')
            ->assertSee('10:00 - 11:30 • Phòng 204, Cầu Giấy')
            ->assertSee('Học sinh cần chú ý')->assertSee('Học sinh Cần Chú Ý')->assertSee('4.5/10')
            ->assertSee('Lương tạm tính tháng 10')->assertSee('300.000đ')
            ->assertSee('Báo cáo chấm công')->assertSee('Vi phạm &amp; Khoản trừ', false)
            ->assertSee(route('teacher.remarks', ['classId' => $this->classModel->id, 'session' => $session->id]), false)
            ->assertSee('data-testid="teacher-bottom-nav"', false)->assertSee('Bảng công')->assertSee('Cá nhân')
            ->assertDontSee('12.500.000')->assertDontSee('Nguyễn Văn A');
    }

    public function test_teacher_attendance_matches_mockup_and_requires_note_for_absences(): void
    {
        $session = $this->makeSession('2026-10-07', '07:30', '08:45');
        $student = $this->student('Nguyễn Minh Quân');

        $this->actingAs($this->teacher)->get(route('teacher.attendance', ['classId' => $this->classModel->id, 'session' => $session->id]))->assertOk()
            ->assertSee('Điểm danh — Kids Explorer MK2, 07/10/2026')
            ->assertSee('Khung giờ: 07:30 – 08:45')->assertSee('Phòng 204 · Cầu Giấy')->assertSee('Sĩ số lớp:')
            ->assertSee('Đang trong cửa sổ điểm danh')->assertSee('Quy định: Buổi học ±24 giờ')
            ->assertSee('Đúng giờ')->assertSee('Đi muộn')->assertSee('Nghỉ có phép')->assertSee('Nghỉ không phép')
            ->assertSee('Quy tắc nghiệp vụ điểm danh dành cho Giáo viên:')
            ->assertSee('Danh sách học sinh trong lớp (Roster)')
            ->assertSee('Phiếu điểm danh sẽ được ghi đè (upsert)', false)->assertSee('Lưu điểm danh')
            ->assertSee('data-testid="teacher-bottom-nav"', false);

        // Nghỉ mà không ghi lý do → bị chặn.
        $this->actingAs($this->teacher)->post(route('teacher.attendance.store', $this->classModel->id), [
            'class_session_id' => $session->id, 'status' => [$student->id => 'absent'],
        ])->assertSessionHasErrors(['note', 'note.'.$student->id]);
        $this->assertSame(0, \App\Models\StudentAttendance::count());

        $this->actingAs($this->teacher)->post(route('teacher.attendance.store', $this->classModel->id), [
            'class_session_id' => $session->id, 'status' => [$student->id => 'absent'], 'note' => [$student->id => 'Phụ huynh báo ốm'],
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_attendances', ['class_session_id' => $session->id, 'status' => 'absent', 'note' => 'Phụ huynh báo ốm']);

        // Buổi quá 24h → vẫn điểm danh bù được nhưng báo ngoài cửa sổ.
        $old = $this->makeSession('2026-10-05', '07:30', '08:45');
        $this->actingAs($this->teacher)->get(route('teacher.attendance', ['classId' => $this->classModel->id, 'session' => $old->id]))
            ->assertOk()->assertSee('Ngoài cửa sổ 24h — điểm danh bù');
    }

    public function test_teacher_remarks_are_saved_per_session_with_draft_and_monsters_columns(): void
    {
        $morning = $this->makeSession('2026-10-07', '07:00', '08:00');
        $afternoon = $this->makeSession('2026-10-07', '14:00', '15:00', ['type' => ClassSession::TYPE_MAKEUP]);
        $present = $this->student('Trần Thị B');
        $absent = $this->student('Lê Văn C');
        foreach ([[$present, 'present', null], [$absent, 'absent', 'Ốm']] as [$st, $status, $note]) {
            \App\Models\StudentAttendance::create(['class_id' => $this->classModel->id, 'class_session_id' => $morning->id, 'student_id' => $st->id,
                'user_id' => $this->teacher->id, 'session_date' => '2026-10-07', 'status' => $status, 'note' => $note]);
        }

        $this->actingAs($this->teacher)->get(route('teacher.remarks', ['classId' => $this->classModel->id, 'session' => $morning->id]))->assertOk()
            ->assertSee('Nhận xét buổi học cho từng học sinh')->assertSee('Lớp Kids Explorer MK2')->assertSee('Buổi 1: 07/10/2026')
            ->assertSee('Monsters (Nhóm)')->assertSee('Monsters (Thưởng)')->assertSee('Thực hành ngữ pháp')->assertSee('Tinh thần học tập')
            ->assertSee('Kết quả')->assertSee('Nhận xét chi tiết')->assertSee('Có mặt')->assertSee('Vắng mặt')
            ->assertSee('Học sinh vắng mặt...')->assertSee('Lưu nháp')->assertSee('Lưu nhận xét');

        // Lưu nháp buổi sáng, lưu chính thức buổi chiều — hai bản ghi riêng theo buổi.
        $this->actingAs($this->teacher)->post(route('teacher.remarks.store', $this->classModel->id), [
            'class_session_id' => $morning->id, 'action' => 'draft',
            'remarks' => [$present->id => ['monsters_group' => '+5', 'monsters_bonus' => '+2', 'grammar' => 'Khá', 'comment' => 'Tiến bộ']],
        ])->assertRedirect(route('teacher.remarks', ['classId' => $this->classModel->id, 'session' => $morning->id]));
        $this->actingAs($this->teacher)->post(route('teacher.remarks.store', $this->classModel->id), [
            'class_session_id' => $afternoon->id, 'action' => 'final',
            'remarks' => [$present->id => ['result' => 'Đạt mục tiêu bài học']],
        ])->assertSessionHasNoErrors();

        $records = \App\Models\AcademicRecord::where('module', 'teacher_remarks')->orderBy('id')->get();
        $this->assertCount(2, $records);
        $this->assertSame($this->classModel->id.'-2026-10-07-s'.$morning->id, $records[0]->record_code);
        $this->assertSame('draft', $records[0]->status);
        $this->assertSame('+2', $records[0]->data[$present->id]['monsters_bonus']);
        $this->assertSame('completed', $records[1]->status);

        $this->actingAs($this->teacher)->get(route('teacher.remarks', ['classId' => $this->classModel->id, 'session' => $morning->id]))
            ->assertSee('Bản nháp')->assertSee('value="+5"', false)->assertSee('Tiến bộ');
    }

    public function test_teacher_homework_form_matches_mockup_with_session_categories_and_locked_submitted_category(): void
    {
        $session = $this->makeSession('2026-10-07', '07:00', '08:00');
        $student = $this->student('Học sinh Nộp Bài');

        $this->actingAs($this->teacher)->get(route('teacher.homework', ['classId' => $this->classModel->id, 'session' => $session->id]))->assertOk()
            ->assertSee('Giao bài tập về nhà')->assertSee('Thông tin chung')->assertSee('Buổi học')->assertSee('Hạn nộp')
            ->assertSee('Ghi chú nhắc nhở cả lớp (Không bắt buộc)')->assertSee('Tài liệu tham khảo (Không bắt buộc)')
            ->assertSee('Link YouTube nghe mẫu')->assertSee('File nghe đính kèm')->assertSee('Link Quizizz luyện thêm')
            ->assertSee('Hạng mục bài tập')->assertSee('Chọn ít nhất 1 hạng mục')
            ->assertSee('Quay video')->assertSee('Viết từ vựng')->assertSee('Workbook')->assertSee('Sách bổ trợ')->assertSee('Quiz')->assertSee('Sách bộ giáo dục')
            ->assertSee('Lưu bài tập');

        // Không chọn hạng mục → lỗi; hạng mục thiếu yêu cầu → lỗi.
        $base = ['class_session_id' => $session->id, 'due_at' => '2026-10-10T20:00'];
        $this->actingAs($this->teacher)->post(route('teacher.homework.store', $this->classModel->id), $base + ['categories' => []])
            ->assertSessionHasErrors('categories');
        $this->actingAs($this->teacher)->post(route('teacher.homework.store', $this->classModel->id), $base + ['categories' => ['video'], 'items' => ['video' => '']])
            ->assertSessionHasErrors('items.video');

        $this->actingAs($this->teacher)->post(route('teacher.homework.store', $this->classModel->id), $base + [
            'categories' => ['video', 'workbook'], 'items' => ['video' => 'Quay video giới thiệu bản thân', 'workbook' => 'Trang 12-13'],
            'class_note' => 'Nộp trước 12h chủ nhật', 'youtube_url' => 'https://youtube.com/watch?v=abc',
        ])->assertSessionHasNoErrors();
        $homework = \App\Models\Homework::sole();
        $this->assertSame($session->id, $homework->class_session_id);
        $this->assertSame('2026-10-10 20:00', $homework->due_at->format('Y-m-d H:i'));
        $this->assertSame(['video' => 'Quay video giới thiệu bản thân', 'workbook' => 'Trang 12-13'], $homework->items);

        // Học sinh nộp bài "video" → hạng mục bị khóa khi sửa, không bỏ được, không xóa được bài tập.
        $this->travel(1)->hours();
        \App\Models\AcademicRecord::create(['screen_key' => 'x', 'module' => 'student_portal', 'record_code' => 'SUB-MK2', 'title' => 'Bài nộp', 'status' => 'submitted',
            'data' => ['student_id' => (string) $student->id, 'homework_type' => 'video']]);
        $this->actingAs($this->teacher)->get(route('teacher.homework', ['classId' => $this->classModel->id, 'edit' => $homework->id]))
            ->assertOk()->assertSee('Sửa bài tập về nhà')->assertSee('Đã có học sinh nộp');
        $this->actingAs($this->teacher)->put(route('teacher.homework.update', [$this->classModel->id, $homework->id]), $base + [
            'categories' => ['workbook'], 'items' => ['workbook' => 'Trang 14'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(['workbook', 'video'], array_keys($homework->fresh()->items));
        $this->actingAs($this->teacher)->delete(route('teacher.homework.destroy', [$this->classModel->id, $homework->id]))->assertSessionHasErrors('homework');
        $this->assertModelExists($homework);
    }

    public function test_teacher_mini_test_scores_use_unit_student_and_four_required_skills(): void
    {
        $curriculum = SyllabusCurriculum::create(['code' => 'SYL-K1', 'title' => 'Kids Early Start']);
        CourseLevel::create(['code' => 'KID-BEG-01', 'name' => 'Kids Beginner 1', 'target' => 'Starters', 'lessons_count' => 24,
            'is_active' => true, 'syllabus_curriculum_id' => $curriculum->id]);
        $unit = \App\Models\SyllabusUnit::create(['curriculum_id' => $curriculum->id, 'stage_id' => $curriculum->stages()->first()->id,
            'unit_number' => 2, 'title' => 'Daily Routines']);
        $student = $this->student('Nguyễn Văn Điểm');

        $this->actingAs($this->teacher)->get(route('teacher.scores', $this->classModel->id))->assertOk()
            ->assertSee('Nhập điểm mini test')->assertSee('Vui lòng chọn thông tin và nhập điểm cho học sinh')
            ->assertSee('Chọn Unit')->assertSee('Unit 2: Daily Routines')->assertSee('Chọn học sinh')->assertSee('Nguyễn Văn Điểm')
            ->assertSee('Điểm kỹ năng')->assertSee('Nghe')->assertSee('Nói')->assertSee('Đọc')->assertSee('Viết')
            ->assertSee('Nhận xét chung (Không bắt buộc)')->assertSee('Lưu điểm');

        $this->actingAs($this->teacher)->post(route('teacher.scores.store', $this->classModel->id), [
            'unit_id' => $unit->id, 'student_id' => $student->id, 'skills' => ['listening' => 8, 'speaking' => 7, 'reading' => '', 'writing' => 6],
        ])->assertSessionHasErrors('skills');
        $this->assertSame('Cần nhập đủ điểm 4 kỹ năng.', session('errors')->first('skills'));

        $this->actingAs($this->teacher)->post(route('teacher.scores.store', $this->classModel->id), [
            'unit_id' => $unit->id, 'student_id' => $student->id, 'skills' => ['listening' => 8, 'speaking' => 7, 'reading' => 9, 'writing' => 6],
            'note' => 'Cần luyện viết',
        ])->assertSessionHasNoErrors();
        $score = \App\Models\MiniTestScore::sole();
        $this->assertSame('Unit 2: Daily Routines', $score->name);
        $this->assertSame($unit->id, $score->syllabus_unit_id);
        $this->assertEquals(7.5, (float) $score->score);
        $this->assertEquals(['listening' => 8, 'speaking' => 7, 'reading' => 9, 'writing' => 6], $score->skill_scores);

        $this->actingAs($this->teacher)->get(route('teacher.scores', ['classId' => $this->classModel->id, 'unit_id' => $unit->id]))
            ->assertSee('Điểm đã nhập — Unit 2: Daily Routines')->assertSee('7.5/10');
    }
}
