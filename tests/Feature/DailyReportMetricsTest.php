<?php

namespace Tests\Feature;

use App\Models\AcademicProject;
use App\Models\AcademicProjectMilestone;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\MaterialOrder;
use App\Models\MiniTestScore;
use App\Models\PlacementTest;
use App\Models\PlacementTestSubmission;
use App\Models\StaffReport;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\StudentTuition;
use App\Models\SupportSession;
use App\Models\SupportTicket;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Models\WorkTask;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Báo cáo ngày lấy số liệu từ hệ thống (chủ dự án 15/10/2026): Học vụ / Học thuật chỉ nhập ghi chú, số liệu tính ở máy chủ
 * và chụp lại lúc nộp; báo cáo trực lớp của Trợ giảng điền sẵn sĩ số / học sinh vắng / mini test dưới 7.
 */
class DailyReportMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ClassModel $class;

    private User $teacher;

    private User $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CA', 'is_active' => true]);
        $this->teacher = $this->makeUser('teacher_fulltime', ['name' => 'Cô Lan']);
        $this->assistant = $this->makeUser('assistant', ['name' => 'TA Minh']);
        $course = Course::create(['name' => 'Starters', 'code' => 'ST', 'total_lessons' => 24, 'tuition_fee' => 3000000, 'is_active' => true]);
        $this->class = ClassModel::create([
            'name' => 'Lớp ST1', 'code' => 'ST1', 'course_id' => $course->id, 'branch_id' => $this->branch->id,
            'teacher_id' => $this->teacher->id, 'assistant_id' => $this->assistant->id,
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2), 'max_capacity' => 12, 'status' => 'active',
        ]);
    }

    private function makeUser(string|array $roles, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['branch_id' => $this->branch->id, 'is_active' => true], $attributes));
        $user->assignRole($roles);

        return $user;
    }

    private function makeSession(array $attributes = []): ClassSession
    {
        return ClassSession::create(array_merge([
            'class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->toDateString(),
            'start_time' => '08:00', 'end_time' => '09:30', 'room' => '302', 'type' => ClassSession::TYPE_REGULAR, 'status' => 'scheduled',
            'teacher_id' => $this->teacher->id, 'assistant_id' => $this->assistant->id,
        ], $attributes));
    }

    private function student(string $name): Student
    {
        return Student::create(['code' => 'HV-'.mb_substr($name, -3), 'name' => $name, 'phone' => '09'.random_int(10000000, 99999999), 'branch_id' => $this->branch->id, 'status' => 'studying']);
    }

    /** @return array<string, array> key => item */
    private static function items(array $sections): array
    {
        return collect($sections)->flatMap(fn ($s) => collect($s['items'])->keyBy('key'))->all();
    }

    public function test_operations_daily_report_is_built_from_system_data_and_snapshotted_on_submit(): void
    {
        $staff = $this->makeUser('academic_staff', ['name' => 'Học vụ A']);
        $today = today()->toDateString();

        $evening = $this->class->replicate()->fill(['name' => 'Lớp ST2', 'code' => 'ST2']);
        $evening->save();
        $taught = $this->makeSession();
        $this->makeSession(['class_id' => $evening->id, 'start_time' => '18:00', 'end_time' => '19:30']);
        $this->makeSession(['start_time' => '10:00', 'end_time' => '11:00', 'status' => 'cancelled', 'teacher_id' => null]);
        $taught->update(['status' => 'completed']);

        $an = $this->student('Nguyễn An');
        $binh = $this->student('Trần Bình');
        StudentAttendance::create(['class_id' => $this->class->id, 'class_session_id' => $taught->id, 'student_id' => $an->id,
            'user_id' => $this->teacher->id, 'session_date' => $today, 'status' => 'present']);
        StudentAttendance::create(['class_id' => $this->class->id, 'class_session_id' => $taught->id, 'student_id' => $binh->id,
            'user_id' => $this->teacher->id, 'session_date' => $today, 'status' => 'absent']);
        SupportSession::create(['class_id' => $this->class->id, 'student_id' => $binh->id, 'teacher_id' => $this->teacher->id,
            'session_date' => $today, 'start_time' => '17:00', 'end_time' => '17:30', 'status' => 'completed']);
        SupportSession::create(['class_id' => $this->class->id, 'student_id' => $an->id, 'teacher_id' => $this->teacher->id,
            'session_date' => $today, 'start_time' => '17:30', 'end_time' => '18:00', 'status' => 'scheduled']);

        $lead = CrmCustomer::create(['code' => 'KH-1', 'name' => 'Lê Hằng', 'phone' => '0933111222', 'stage' => 'new',
            'source' => 'Facebook', 'branch_id' => $this->branch->id, 'assigned_user_id' => $staff->id, 'appointment_at' => now()->setTime(15, 0)]);
        $test = PlacementTest::create(['code' => 'T1', 'title' => 'Đề', 'is_active' => true]);
        PlacementTestSubmission::create(['placement_test_id' => $test->id, 'customer_id' => $lead->id, 'candidate_name' => $lead->name,
            'candidate_phone' => $lead->phone, 'status' => 'pending']);

        $tuition = StudentTuition::create(['student_id' => $an->id, 'branch_id' => $this->branch->id, 'total_amount' => 3000000, 'final_amount' => 3000000,
            'paid_amount' => 0, 'debt_amount' => 3000000, 'due_date' => now()->addDays(10), 'status' => 'unpaid']);
        foreach (['approved' => 1500000, 'pending' => 500000, 'draft' => 900000] as $status => $amount) {
            TuitionReceipt::create(['receipt_number' => 'PT-'.$status, 'student_tuition_id' => $tuition->id, 'student_id' => $an->id,
                'amount' => $amount, 'payment_method' => 'cash', 'payment_date' => now(), 'status' => $status]);
        }
        SupportTicket::create(['code' => 'TK-1', 'title' => 'Phòng 302 hỏng điều hòa', 'category' => 'facilities', 'priority' => 'high',
            'status' => 'open', 'creator_id' => $staff->id, 'description' => 'Nóng']);
        StaffReport::create(['user_id' => $staff->id, 'class_id' => $this->class->id, 'type' => 'journal', 'title' => 'Sự vụ',
            'content' => 'Phụ huynh bé Bình phản ánh giờ tan học', 'report_date' => $today]);

        $this->actingAs($staff)->get(route('reports.periodic.daily', ['date' => $today]))->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $page->component('Reports/DailyOps')->where('live', true)->has('sections', 4)->where('notes', [])
                    ->where('sections', function ($sections) {
                        $items = self::items(collect($sections)->all());
                        $this->assertSame(['2', '1', '1', '1'], [$items['classes_planned']['value'], $items['classes_actual']['value'], $items['teachers']['value'], $items['assistants']['value']]);
                        $this->assertSame('1/2', $items['support_students']['value']);
                        $this->assertSame('1 / 1 hẹn', $items['test_students']['value']);
                        $this->assertSame(['1', '2'], [$items['new_data']['value'], $items['fees']['value']]);
                        $this->assertContains('Tổng tiền: 2.000.000đ', collect($items['fees']['details'])->pluck('text')->all());
                        $this->assertEqualsCanonicalizing(['Đã dạy', 'Chưa điểm danh', 'Nghỉ / hủy'], collect($items['classes_list']['details'])->pluck('tag')->all());
                        $situation = collect($items['class_situation']['details'])->pluck('text')->implode("\n");
                        $this->assertStringContainsString('vắng 1 (Trần Bình)', $situation);
                        $this->assertStringContainsString('Phụ huynh bé Bình phản ánh', $situation);
                        $this->assertStringContainsString('Phòng 302 hỏng điều hòa', collect($items['facilities']['details'])->pluck('text')->implode("\n"));
                        // Buổi có TA mà chưa có báo cáo trực lớp.
                        $this->assertContains('Chưa báo cáo trực lớp', collect($items['assistants']['details'])->pluck('tag')->all());

                        return true;
                    });
            });

        // Số liệu gửi kèm từ trình duyệt bị bỏ qua; chỉ ghi chú là nhập tay. Task thiếu hạn xử lý → lỗi.
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), ['date' => $today, 'tasks' => [['title' => 'Kê thêm bàn']]])
            ->assertSessionHasErrors('tasks.0.due_date');
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), [
            'date' => $today,
            'fields' => ['classes_planned' => 99],
            'notes' => ['operations' => 'Lớp ST1 cần thêm bàn', 'media' => 'Đăng bài khai giảng https://fb.com/p/1', 'other' => 'bỏ qua'],
            'tasks' => [['title' => 'Kê thêm bàn ST1', 'content' => 'Lớp ST1 cần thêm 2 bàn', 'due_date' => $today, 'assignee_id' => '']],
        ])->assertSessionHasNoErrors()->assertRedirect(route('reports.periodic.daily', ['date' => $today]));

        $report = StaffReport::where('kind', 'daily_ops')->sole();
        $this->assertSame(2, $report->data['fields']['classes_planned']);
        $this->assertSame('1/2', $report->data['fields']['support_students']);
        $this->assertSame(['operations' => 'Lớp ST1 cần thêm bàn', 'media' => 'Đăng bài khai giảng https://fb.com/p/1'], $report->data['notes']);
        $this->assertStringContainsString('Số lớp thực tế: 1', $report->content);
        $this->assertStringContainsString('- Ghi chú: Lớp ST1 cần thêm bàn', $report->content);
        $this->assertStringContainsString('Lớp ST1 cần thêm 2 bàn', WorkTask::sole()->description);

        // Dữ liệu gốc đổi sau khi nộp: báo cáo vẫn giữ số lúc nộp, "Cập nhật số liệu" (refresh) mới tính lại.
        $late = $this->class->replicate()->fill(['name' => 'Lớp ST3', 'code' => 'ST3']);
        $late->save();
        $this->makeSession(['class_id' => $late->id, 'start_time' => '20:00', 'end_time' => '21:00']);
        $this->actingAs($staff)->get(route('reports.periodic.daily', ['date' => $today]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('live', false)->where('notes.operations', 'Lớp ST1 cần thêm bàn')
                ->where('sections.operations.items.0.value', '2'));
        $this->actingAs($staff)->get(route('reports.periodic.daily', ['date' => $today, 'refresh' => 1]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('live', true)->where('sections.operations.items.0.value', '3'));
        // Sửa ghi chú (không bấm "Cập nhật số liệu") giữ nguyên số đã chụp; Lưu sau khi cập nhật (refresh=1) mới ghi số mới.
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), ['date' => $today, 'notes' => ['operations' => 'Sửa chính tả']]);
        $this->assertSame(2, $report->fresh()->data['fields']['classes_planned']);
        $this->assertSame('2', $report->fresh()->data['auto']['sections']['operations']['items'][0]['value']);
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), ['date' => $today, 'refresh' => 1, 'notes' => ['operations' => 'Sửa chính tả']]);
        $this->assertSame(3, $report->fresh()->data['fields']['classes_planned']);
        $this->assertSame(1, WorkTask::count());

        // Tổng quan Học thuật vẫn đọc được số chính từ `fields`.
        $this->actingAs($this->makeUser('admin'))->get(route('academic.reports', ['tab' => 'daily']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('staffDailyReports.0.classes', '1 / 3'));
    }

    public function test_legacy_manual_daily_report_still_shows_its_typed_values(): void
    {
        $staff = $this->makeUser('academic_staff');
        $yesterday = today()->subDay()->toDateString();
        StaffReport::create(['user_id' => $staff->id, 'type' => 'daily', 'kind' => 'daily_ops', 'period_key' => $yesterday, 'report_date' => $yesterday,
            'title' => 'Báo cáo ngày', 'content' => 'cũ', 'status' => 'submitted', 'data' => ['fields' => ['classes_planned' => 12, 'facilities' => 'Hỏng loa']]]);

        $this->actingAs($staff)->get(route('reports.periodic.daily', ['date' => $yesterday]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('live', true)
                ->where('legacyFields', [['label' => 'Số lớp kế hoạch', 'value' => 12], ['label' => 'Cơ sở vật chất', 'value' => 'Hỏng loa']]));
    }

    public function test_academic_daily_report_uses_academic_data(): void
    {
        $lead = $this->makeUser('academic_lead', ['name' => 'Học thuật B']);
        $project = AcademicProject::create(['code' => 'DA-1', 'name' => 'Giáo trình Movers', 'owner_id' => $lead->id, 'status' => 'in_progress',
            'start_date' => today()->subWeek(), 'deadline' => today()->addMonth(), 'created_by' => $lead->id]);
        AcademicProjectMilestone::create(['academic_project_id' => $project->id, 'title' => 'Unit 1-3', 'assignee_id' => $lead->id, 'due_date' => today(), 'status' => 'in_progress']);
        AcademicProjectMilestone::create(['academic_project_id' => $project->id, 'title' => 'Đề cương', 'assignee_id' => $lead->id, 'due_date' => today()->subDays(2), 'status' => 'todo']);
        $an = $this->student('Nguyễn An');
        MiniTestScore::create(['class_id' => $this->class->id, 'student_id' => $an->id, 'user_id' => $this->teacher->id, 'name' => 'Unit 2', 'score' => 5.5, 'max_score' => 10, 'test_date' => today()]);
        MaterialOrder::create(['code' => 'OD-1', 'branch_id' => $this->branch->id, 'class_id' => $this->class->id, 'requester_id' => $this->teacher->id,
            'category' => MaterialOrder::CATEGORY_ACADEMIC, 'title' => 'Flashcard Unit 3', 'quantity' => 1, 'use_date' => today()->addDays(2), 'due_at' => now()->addDay(), 'status' => 'pending']);

        $this->actingAs($lead)->get(route('reports.periodic.daily', ['date' => today()->toDateString()]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('audience', 'Học thuật')
                ->where('sections', function ($sections) {
                    $sections = collect($sections)->all();
                    $this->assertSame(['projects', 'materials', 'syllabus', 'quality'], array_keys($sections));
                    $items = self::items($sections);
                    $this->assertSame(['1', '1'], [$items['milestones_due']['value'], $items['milestones_overdue']['value']]);
                    $this->assertSame('1 bài · 1 dưới 7', $items['mini_tests']['value']);
                    $this->assertSame('1', $items['academic_orders']['value']);

                    return true;
                }));

        $this->actingAs($lead)->post(route('reports.periodic.daily.store'), ['date' => today()->toDateString(), 'notes' => ['projects' => 'Đang chờ GV duyệt']])
            ->assertSessionHasNoErrors();
        $report = StaffReport::where('kind', 'daily_ops')->sole();
        $this->assertSame('Học thuật', $report->data['auto']['audience']);
        $this->assertStringContainsString('Mốc quá hạn: 1', $report->content);
    }

    public function test_class_report_form_prefills_attendance_and_support_suggestions(): void
    {
        $session = $this->makeSession(['status' => 'completed']);
        $an = $this->student('Nguyễn An');
        $binh = $this->student('Trần Bình');
        foreach ([$an, $binh] as $student) {
            ClassEnrollment::create(['class_id' => $this->class->id, 'student_id' => $student->id, 'status' => 'pending', 'enrolled_at' => today()->subMonth()]);
        }
        StudentAttendance::create(['class_id' => $this->class->id, 'class_session_id' => $session->id, 'student_id' => $an->id,
            'user_id' => $this->teacher->id, 'session_date' => today(), 'status' => 'present']);
        StudentAttendance::create(['class_id' => $this->class->id, 'class_session_id' => $session->id, 'student_id' => $binh->id,
            'user_id' => $this->teacher->id, 'session_date' => today(), 'status' => 'absent']);
        MiniTestScore::create(['class_id' => $this->class->id, 'student_id' => $an->id, 'user_id' => $this->teacher->id, 'name' => 'Unit 2', 'score' => 6, 'max_score' => 10, 'test_date' => today()->subDays(3)]);

        $this->actingAs($this->assistant)->get(route('tasks.class-reports.create', ['class_id' => $this->class->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Tasks/ClassReportCreate')
                ->where("sessionDetails.{$session->id}.attendance.text", 'Sĩ số (theo điểm danh): 1/2 · Vắng: Trần Bình')
                // Học sinh vắng đã tự vào danh sách cần chú ý chung của buổi → hiện chỉ đọc, không gợi ý nhập lại.
                ->where("sessionDetails.{$session->id}.listed.0.student_id", $binh->id)
                ->where("sessionDetails.{$session->id}.listed.0.source", 'Vắng học')
                ->where("sessionDetails.{$session->id}.suggested", [])
                ->where('testSuggestions.0.student_id', $an->id)
                ->where('testSuggestions.0.reason', 'Mini test Unit 2: 6/10 ('.today()->subDays(3)->format('d/m').')'));

        $this->actingAs($this->assistant)->post(route('tasks.class-reports.store'), [
            'class_id' => $this->class->id, 'class_session_id' => $session->id, 'hom_nay_hoc_gi' => 'Unit 3', 'nhat_ky_day' => 'Lớp sôi nổi',
            'board_image_url' => 'https://example.com/a.jpg',
        ])->assertSessionHasNoErrors();
        $this->assertSame("Sĩ số (theo điểm danh): 1/2 · Vắng: Trần Bình\nLớp sôi nổi", ClassReport::sole()->teaching_log);
    }
}
