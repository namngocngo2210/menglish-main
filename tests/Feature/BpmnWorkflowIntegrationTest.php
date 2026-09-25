<?php

namespace Tests\Feature;

use App\Models\BigTest;
use App\Models\BigTestResult;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\PayrollPeriod;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\SupportSession;
use App\Models\TeacherTimesheet;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BpmnWorkflowIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $teacher;

    private User $academicStaff;

    private ClassModel $classModel;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-BPMN', 'is_active' => true]);
        $this->manager = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $this->manager->assignRole('manager');
        $this->teacher = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id, 'hourly_rate' => 300000]);
        $this->teacher->assignRole('teacher');
        $this->academicStaff = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $this->academicStaff->assignRole('academic_staff');

        $course = Course::create(['name' => 'IELTS BPMN', 'code' => 'IELTS-BPMN', 'total_lessons' => 24, 'is_active' => true]);
        $this->classModel = ClassModel::create([
            'name' => 'Lớp BPMN', 'code' => 'BPMN-01', 'course_id' => $course->id,
            'program' => $course->name, 'branch_id' => $branch->id, 'teacher_id' => $this->teacher->id,
            'room' => 'P101', 'status' => 'pending_schedule',
        ]);
        $this->student = Student::create([
            'name' => 'Học viên BPMN', 'code' => 'HV-BPMN', 'phone' => '0901000001',
            'current_class_id' => $this->classModel->id, 'branch_id' => $branch->id, 'status' => 'studying',
        ]);
    }

    public function test_schedule_attendance_timesheet_and_payroll_are_one_approved_flow(): void
    {
        $dayNames = [1 => 'Thứ 2', 2 => 'Thứ 3', 3 => 'Thứ 4', 4 => 'Thứ 5', 5 => 'Thứ 6', 6 => 'Thứ 7', 7 => 'Chủ nhật'];
        $today = now();
        $tomorrow = now()->addDay();

        $this->actingAs($this->manager)->post(route('tasks.schedule-config.update'), [
            'class_id' => $this->classModel->id,
            'start_date' => $today->toDateString(),
            'end_date' => $tomorrow->toDateString(),
            'slot1_day' => $dayNames[$today->isoWeekday()], 'slot1_start' => '08:00', 'slot1_end' => '10:00',
            'slot2_day' => $dayNames[$tomorrow->isoWeekday()], 'slot2_start' => '08:00', 'slot2_end' => '10:00',
        ])->assertRedirect();

        $this->assertDatabaseCount('class_sessions', 2);
        $this->assertDatabaseHas('classes', ['id' => $this->classModel->id, 'status' => 'active']);

        $this->actingAs($this->teacher)->post(route('teacher.checkin'), ['class_ids' => [$this->classModel->id]])->assertRedirect();
        $timesheet = TeacherTimesheet::firstOrFail();
        $this->assertSame('pending_review', $timesheet->status);
        $this->assertNotNull($timesheet->class_session_id);

        $this->actingAs($this->teacher)->post(route('teacher.attendance.store', $this->classModel->id), [
            'status' => [$this->student->id => 'present'],
        ])->assertRedirect();
        $attendance = StudentAttendance::firstOrFail();
        $this->assertSame('pending_review', $attendance->review_status);

        $this->actingAs($this->academicStaff)->post(route('kpi.attendance-review.update', $attendance->id), ['decision' => 'approved'])->assertRedirect();
        $this->assertSame(1, $this->student->fresh()->attended_lessons);

        $period = PayrollPeriod::create([
            'code' => 'PR-BPMN', 'title' => 'Bảng lương BPMN', 'month' => $today->month, 'year' => $today->year,
            'start_date' => $today->copy()->startOfMonth(), 'end_date' => $today->copy()->endOfMonth(), 'status' => 'draft',
        ]);
        $period->calculatePayrollForPeriod();
        $this->assertDatabaseMissing('payroll_records', ['payroll_period_id' => $period->id, 'user_id' => $this->teacher->id]);

        $this->actingAs($this->manager)->post(route('payroll.timesheets.review', $timesheet->id), ['decision' => 'valid'])->assertRedirect();
        $period->calculatePayrollForPeriod();
        $this->assertDatabaseHas('payroll_records', ['payroll_period_id' => $period->id, 'user_id' => $this->teacher->id, 'actual_hours' => 2]);
    }

    public function test_class_creation_with_rendered_timetable_creates_sessions_and_activates_class(): void
    {
        $firstDay = now()->addDays(7)->toDateString();
        $secondDay = now()->addDays(9)->toDateString();

        $payload = [
            'ten_lop' => 'Lớp BPMN TKB', 'ma_lop' => 'BPMN-TKB-01', 'chi_nhanh' => $this->classModel->branch_id,
            'chuong_trinh' => 'IELTS BPMN', 'cap_do' => 'B1', 'si_so_toi_da' => 12,
            'phong_hoc' => 'P202', 'giao_vien_chinh' => $this->teacher->id,
            'schedule_sessions_json' => json_encode([
                ['date' => $secondDay, 'dateFormatted' => 'ignored', 'shift' => 'Ca 3', 'start' => '14:00', 'end' => '15:30', 'room' => 'P302', 'occupiedRooms' => []],
                ['date' => $firstDay, 'dateFormatted' => 'ignored', 'shift' => 'Ca 1', 'start' => '08:00', 'end' => '09:30', 'room' => '', 'occupiedRooms' => []],
            ]),
        ];

        $this->actingAs($this->manager)->post(route('classes.store'), $payload)->assertRedirect();

        $class = ClassModel::where('code', 'BPMN-TKB-01')->firstOrFail();
        $this->assertSame('active', $class->status);
        $this->assertSame($firstDay, $class->start_date->toDateString());
        $this->assertSame($secondDay, $class->end_date->toDateString());
        $this->assertStringContainsString('Ca 1 08:00-09:30', $class->schedule_text);

        $this->assertSame(2, $class->sessions()->count());
        $firstSession = $class->sessions()->orderBy('date')->first();
        $this->assertSame('Ca 1', $firstSession->shift_name);
        $this->assertSame('08:00', $firstSession->start_time->format('H:i'));
        // Buổi không chọn phòng riêng phải fallback về phòng dự kiến của lớp
        $this->assertSame('P202', $firstSession->room);
        $this->assertSame($this->teacher->id, $firstSession->teacher_id);
        $this->assertSame('scheduled', $firstSession->status);
    }

    public function test_class_creation_timetable_conflict_is_rejected_without_creating_class(): void
    {
        ClassSession::create([
            'class_id' => $this->classModel->id, 'branch_id' => $this->classModel->branch_id,
            'date' => now()->addDays(7)->toDateString(), 'shift_name' => 'Ca 1',
            'start_time' => '08:00', 'end_time' => '09:30', 'room' => 'P101',
            'teacher_id' => $this->teacher->id, 'status' => 'scheduled',
        ]);

        $this->actingAs($this->manager)->post(route('classes.store'), [
            'ten_lop' => 'Lớp xung đột', 'ma_lop' => 'BPMN-CONFLICT', 'chi_nhanh' => $this->classModel->branch_id,
            'chuong_trinh' => 'IELTS BPMN', 'cap_do' => 'B1', 'si_so_toi_da' => 12,
            'giao_vien_chinh' => $this->teacher->id,
            'schedule_sessions_json' => json_encode([
                ['date' => now()->addDays(7)->toDateString(), 'shift' => 'Ca 2', 'start' => '09:00', 'end' => '10:30', 'room' => ''],
            ]),
        ])->assertSessionHasErrors('schedule_sessions_json');

        $this->assertDatabaseMissing('classes', ['code' => 'BPMN-CONFLICT']);
        $this->assertSame(1, ClassSession::count());
    }

    public function test_makeup_session_can_be_taught_by_teaching_assistant(): void
    {
        $assistant = User::factory()->create(['is_active' => true, 'branch_id' => $this->classModel->branch_id]);
        $assistant->assignRole('assistant');

        $this->actingAs($this->manager)->post(route('tasks.support-sessions.store'), [
            'class_id' => $this->classModel->id, 'student_id' => $this->student->id, 'teacher_id' => $assistant->id,
            'session_date' => now()->addDay()->toDateString(), 'start_time' => '09:00', 'end_time' => '10:30',
            'room' => 'P203', 'reason' => 'TA dạy bù buổi vắng',
        ])->assertRedirect();

        $support = SupportSession::firstOrFail();
        $this->assertSame($assistant->id, $support->teacher_id);

        $this->actingAs($assistant)->post(route('tasks.support-sessions.complete', $support->id))->assertRedirect();
        $this->assertDatabaseHas('teacher_timesheets', ['user_id' => $assistant->id, 'type' => '1on1', 'status' => 'pending_review']);
    }

    public function test_academic_staff_can_file_tuition_receipts_but_cannot_approve(): void
    {
        $this->assertTrue($this->academicStaff->can('tuition.create'));
        $this->assertFalse($this->academicStaff->can('tuition.approve'));
        $this->assertFalse($this->academicStaff->can('tuition.reject'));
    }

    public function test_class_update_preserves_foreign_teacher_when_left_empty(): void
    {
        $foreignTeacher = User::factory()->create(['is_active' => true, 'branch_id' => $this->classModel->branch_id]);
        $foreignTeacher->assignRole('teacher');
        $this->classModel->update(['foreign_teacher_id' => $foreignTeacher->id]);

        $this->actingAs($this->manager)->put(route('classes.update', $this->classModel->id), [
            'ten_lop' => $this->classModel->name,
            'ma_lop' => $this->classModel->code,
            'chi_nhanh' => $this->classModel->branch_id,
            'chuong_trinh' => $this->classModel->program,
            'cap_do' => 'B1',
            'si_so_toi_da' => 12,
            // không gửi giao_vien_nn — không được phép làm mất GVNN hiện tại
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($foreignTeacher->id, $this->classModel->fresh()->foreign_teacher_id);
    }

    public function test_class_update_can_clear_teacher_assistant_and_room_when_sent_empty(): void
    {
        $this->classModel->update(['assistant_id' => $this->academicStaff->id]);

        $this->actingAs($this->manager)->put(route('classes.update', $this->classModel->id), [
            'ten_lop' => $this->classModel->name,
            'ma_lop' => $this->classModel->code,
            'chi_nhanh' => $this->classModel->branch_id,
            'chuong_trinh' => $this->classModel->program,
            'cap_do' => 'B1',
            'si_so_toi_da' => 12,
            // Form gửi "-- Chưa gán --" (rỗng) → phải gỡ được GV/TA/phòng
            'giao_vien_chinh' => '',
            'tro_giang' => '',
            'giao_vien_nn' => '',
            'phong_hoc' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $this->classModel->fresh();
        $this->assertNull($fresh->teacher_id);
        $this->assertNull($fresh->assistant_id);
        $this->assertNull($fresh->room);
    }

    public function test_class_update_propagates_teacher_and_room_changes_to_scheduled_sessions(): void
    {
        $session = ClassSession::create([
            'class_id' => $this->classModel->id, 'branch_id' => $this->classModel->branch_id,
            'date' => now()->addDay()->toDateString(), 'shift_name' => 'Ca 1',
            'start_time' => '08:00', 'end_time' => '09:30', 'room' => 'P101',
            'teacher_id' => $this->teacher->id, 'status' => 'scheduled',
        ]);
        $completed = ClassSession::create([
            'class_id' => $this->classModel->id, 'branch_id' => $this->classModel->branch_id,
            'date' => now()->subDay()->toDateString(), 'shift_name' => 'Ca 1',
            'start_time' => '08:00', 'end_time' => '09:30', 'room' => 'P101',
            'teacher_id' => $this->teacher->id, 'status' => 'completed',
        ]);

        $this->actingAs($this->manager)->put(route('classes.update', $this->classModel->id), [
            'ten_lop' => $this->classModel->name,
            'chi_nhanh' => $this->classModel->branch_id,
            'chuong_trinh' => $this->classModel->program,
            'cap_do' => 'B1',
            'si_so_toi_da' => 12,
            'giao_vien_chinh' => $this->manager->id,
            'phong_hoc' => 'P202',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $session->refresh();
        $this->assertSame($this->manager->id, $session->teacher_id);
        $this->assertSame('P202', $session->room);

        // Buổi đã hoàn tất giữ nguyên dữ liệu lịch sử.
        $completed->refresh();
        $this->assertSame($this->teacher->id, $completed->teacher_id);
        $this->assertSame('P101', $completed->room);
    }

    public function test_academic_list_search_does_not_leak_cancelled_or_other_branch_classes(): void
    {
        $cancelled = ClassModel::create([
            'name' => 'Lớp Đã Hủy Bí Mật', 'code' => 'CANCEL-XYZ', 'course_id' => $this->classModel->course_id,
            'program' => 'IELTS BPMN', 'branch_id' => $this->classModel->branch_id, 'status' => 'cancelled',
        ]);

        // Trước đây orWhere không bọc closure: tìm theo code thoát cả filter status + chi nhánh.
        $this->actingAs($this->manager)
            ->get(route('classes.academic-list', ['search' => 'CANCEL-XYZ']))
            ->assertOk()
            ->assertDontSee('Lớp Đã Hủy Bí Mật');

        $this->actingAs($this->manager)
            ->get(route('classes.academic-list', ['search' => 'BPMN-01']))
            ->assertOk()
            ->assertSee($this->classModel->name);
        $this->assertNotNull($cancelled->id);
    }

    public function test_class_store_rejects_teacher_without_teaching_role(): void
    {
        $sales = User::factory()->create(['is_active' => true, 'branch_id' => $this->classModel->branch_id]);
        $sales->assignRole('sales_consultant');

        $this->actingAs($this->manager)->post(route('classes.store'), [
            'ten_lop' => 'Lớp Gán Sai GV', 'chi_nhanh' => $this->classModel->branch_id,
            'chuong_trinh' => 'IELTS BPMN', 'cap_do' => 'B1', 'si_so_toi_da' => 10,
            'giao_vien_chinh' => $sales->id, // sales không phải giáo viên → bị chặn
        ])->assertSessionHasErrors('giao_vien_chinh');

        $this->assertDatabaseMissing('classes', ['name' => 'Lớp Gán Sai GV']);
    }

    public function test_cancelled_sessions_do_not_block_class_scheduling(): void
    {
        $day = now()->addDays(5)->toDateString();

        // Buổi ĐÃ HỦY của lớp khác cùng khung giờ + cùng giáo viên: không được chặn.
        ClassSession::create([
            'class_id' => $this->classModel->id, 'branch_id' => $this->classModel->branch_id,
            'date' => $day, 'shift_name' => 'Ca 1', 'start_time' => '08:00', 'end_time' => '09:30',
            'room' => 'P101', 'teacher_id' => $this->teacher->id, 'status' => 'cancelled',
        ]);

        $this->actingAs($this->manager)->post(route('classes.store'), [
            'ten_lop' => 'Lớp Tái Sử Dụng Phòng', 'chi_nhanh' => $this->classModel->branch_id,
            'chuong_trinh' => 'IELTS BPMN', 'cap_do' => 'B1', 'si_so_toi_da' => 10,
            'phong_hoc' => 'P101', 'giao_vien_chinh' => $this->teacher->id,
            'schedule_sessions_json' => json_encode([
                ['date' => $day, 'shift' => 'Ca 1', 'start' => '08:00', 'end' => '09:30', 'room' => 'P101'],
            ]),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('classes', ['name' => 'Lớp Tái Sử Dụng Phòng']);
    }

    public function test_cancelled_sessions_do_not_block_schedule_config(): void
    {
        $otherClass = ClassModel::create([
            'name' => 'Lớp Khác', 'code' => 'BPMN-OTHER', 'course_id' => $this->classModel->course_id,
            'program' => 'IELTS BPMN', 'branch_id' => $this->classModel->branch_id,
            'teacher_id' => $this->teacher->id, 'room' => 'P101', 'status' => 'active',
        ]);
        ClassSession::create([
            'class_id' => $otherClass->id, 'branch_id' => $otherClass->branch_id,
            'date' => now()->addDays(5)->toDateString(), 'shift_name' => 'Ca 1',
            'start_time' => '08:00', 'end_time' => '09:30', 'room' => 'P101',
            'teacher_id' => $this->teacher->id, 'status' => 'cancelled',
        ]);

        $this->actingAs($this->manager)->post(route('tasks.schedule-config.update'), [
            'class_id' => $this->classModel->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'slot1_day' => $this->weekdayName(now()->addDays(5)),
            'slot1_start' => '08:00', 'slot1_end' => '09:30',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, ClassSession::where('class_id', $this->classModel->id)
            ->where('status', 'scheduled')->count());
    }

    private function weekdayName(\Carbon\CarbonInterface $date): string
    {
        return ['Chủ nhật', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7'][$date->dayOfWeek];
    }

    public function test_class_update_rejects_invalid_status(): void
    {
        $this->actingAs($this->manager)->put(route('classes.update', $this->classModel->id), [
            'ten_lop' => $this->classModel->name,
            'chi_nhanh' => $this->classModel->branch_id,
            'chuong_trinh' => $this->classModel->program,
            'cap_do' => $this->classModel->level,
            'si_so_toi_da' => 12,
            'status' => 'tự-do',
        ])->assertSessionHasErrors('status');
    }

    public function test_class_delete_blocked_with_active_enrollments_or_scheduled_sessions(): void
    {
        // Lớp còn buổi học đã lên lịch: bị chặn xóa
        ClassSession::create([
            'class_id' => $this->classModel->id, 'branch_id' => $this->classModel->branch_id,
            'date' => now()->addDay()->toDateString(), 'shift_name' => 'Ca 1',
            'start_time' => '08:00', 'end_time' => '09:30', 'teacher_id' => $this->teacher->id,
            'status' => 'scheduled',
        ]);
        $this->actingAs($this->manager)->delete(route('classes.destroy', $this->classModel->id))
            ->assertStatus(422);
        $this->assertNotNull(ClassModel::find($this->classModel->id));

        // Lớp còn học viên đang xếp lớp: bị chặn xóa
        ClassSession::where('class_id', $this->classModel->id)->delete();
        $enrollment = ClassEnrollment::create([
            'student_id' => $this->student->id,
            'class_id' => $this->classModel->id,
            'status' => 'pending',
        ]);
        $this->actingAs($this->manager)->delete(route('classes.destroy', $this->classModel->id))
            ->assertStatus(422);

        // Lớp trống: xóa được
        $enrollment->delete();
        $this->actingAs($this->manager)->delete(route('classes.destroy', $this->classModel->id))
            ->assertRedirect();
        $this->assertSoftDeleted('classes', ['id' => $this->classModel->id]);
    }

    public function test_schedule_config_rejects_overlapping_slots_and_out_of_bounds_configs(): void
    {
        $dayNames = [1 => 'Thứ 2', 2 => 'Thứ 3', 3 => 'Thứ 4', 4 => 'Thứ 5', 5 => 'Thứ 6', 6 => 'Thứ 7', 7 => 'Chủ nhật'];
        $today = now();
        $todayName = $dayNames[$today->isoWeekday()];
        $tomorrowName = $dayNames[$today->copy()->addDay()->isoWeekday()];

        // Hai ca chồng giờ trong cùng ngày
        $this->actingAs($this->manager)->post(route('tasks.schedule-config.update'), [
            'class_id' => $this->classModel->id,
            'start_date' => $today->copy()->toDateString(),
            'end_date' => $today->copy()->addWeek()->toDateString(),
            'slot1_day' => $todayName, 'slot1_start' => '08:00', 'slot1_end' => '10:00',
            'slot2_day' => $todayName, 'slot2_start' => '09:00', 'slot2_end' => '11:00',
        ])->assertSessionHasErrors('slot2_start');
        $this->assertSame(0, ClassSession::count());

        // Khoảng thời gian không chứa ngày học nào của hai ca -> không tạo được buổi nào
        // (cả hai ca đều học vào thứ của hôm nay, còn khoảng xếp lịch là mai và mốt)
        $this->actingAs($this->manager)->post(route('tasks.schedule-config.update'), [
            'class_id' => $this->classModel->id,
            'start_date' => $today->copy()->addDay()->toDateString(),
            'end_date' => $today->copy()->addDays(2)->toDateString(),
            'slot1_day' => $todayName, 'slot1_start' => '08:00', 'slot1_end' => '10:00',
            'slot2_day' => $todayName, 'slot2_start' => '14:00', 'slot2_end' => '16:00',
        ])->assertSessionHasErrors('start_date');

        // Cấu hình hợp lệ vẫn tạo được buổi học
        $this->actingAs($this->manager)->post(route('tasks.schedule-config.update'), [
            'class_id' => $this->classModel->id,
            'start_date' => $today->copy()->toDateString(),
            'end_date' => $today->copy()->addDays(6)->toDateString(),
            'slot1_day' => $todayName, 'slot1_start' => '08:00', 'slot1_end' => '10:00',
            'slot2_day' => $tomorrowName, 'slot2_start' => '14:00', 'slot2_end' => '16:00',
        ])->assertRedirect();
        $this->assertGreaterThan(0, ClassSession::count());
    }

    public function test_makeup_session_and_big_test_require_review_before_pay_or_parent_notification(): void
    {
        $date = now()->addDay();
        $this->actingAs($this->manager)->post(route('tasks.support-sessions.store'), [
            'class_id' => $this->classModel->id, 'student_id' => $this->student->id, 'teacher_id' => $this->teacher->id,
            'session_date' => $date->toDateString(), 'start_time' => '14:00', 'end_time' => '15:30',
            'room' => 'P102', 'reason' => 'Bù buổi vắng',
        ])->assertRedirect();
        $support = SupportSession::firstOrFail();
        $this->actingAs($this->teacher)->post(route('tasks.support-sessions.complete', $support->id))->assertRedirect();
        $this->assertDatabaseHas('teacher_timesheets', ['class_session_id' => $support->class_session_id, 'type' => '1on1', 'status' => 'pending_review']);
        $this->assertSame('completed', ClassSession::find($support->class_session_id)->status);

        $test = BigTest::create([
            'code' => 'BT-BPMN', 'title' => 'Big Test BPMN', 'class_id' => $this->classModel->id,
            'test_type' => 'midterm', 'scheduled_at' => now()->addDays(2), 'room' => 'Lab',
            'is_distributed' => false, 'status' => 'draft',
        ]);
        $this->actingAs($this->manager)->post(route('syllabus.big-tests.approve', $test->id))->assertRedirect();
        $this->actingAs($this->teacher)->post(route('syllabus.big-tests.results.store', $test->id), [
            'results' => [[
                'student_id' => $this->student->id, 'listening_score' => 7, 'reading_score' => 7,
                'writing_score' => 6, 'speaking_score' => 6, 'progress_note' => 'Đạt tiến bộ',
            ]],
        ])->assertRedirect();
        $result = BigTestResult::firstOrFail();
        $this->assertSame('pending_review', $result->status);

        $this->actingAs($this->manager)->post(route('syllabus.big-tests.results.approve', $test->id))->assertRedirect();
        $this->assertSame('approved', $result->fresh()->status);
    }
}
