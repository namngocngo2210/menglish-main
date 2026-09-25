<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\BigTest;
use App\Models\BigTestOrder;
use App\Models\BigTestResult;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassReportStudentSupport;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\CrmCustomer;
use App\Models\Holiday;
use App\Models\MiniTestScore;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\SupportSession;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Models\SyllabusUnit;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Services\SupportListService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Nghiệm thu Phase 2 (docs/audit-report-and-roadmap.md — Phần C, "Kết quả đạt được"):
 * mở lớp → sinh lịch → giao chặng → dạy và điểm danh → Big Test → phụ huynh nhận Zalo kết quả,
 * qua HTTP bằng đúng vai trò thật, theo A6 (Q4: Giáo trình → Chặng → Unit → Buổi, mỗi lớp 1 chặng mở,
 * chặng đóng khi Big Test duyệt & gửi PH rồi tự mở chặng kế).
 */
class Phase2AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $academic;

    private User $lead;

    private User $teacher;

    private User $assistant;

    private User $gvnn;

    private User $busyGvnn;

    private User $otherTeacher;

    private CourseLevel $level;

    private Course $course;

    private ClassModel $otherClass;

    private Holiday $holiday;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở Nghiệm thu 2', 'code' => 'N2', 'is_active' => true]);
        $this->admin = $this->userWithRole('admin');
        $this->academic = $this->userWithRole('academic_staff');
        $this->lead = $this->userWithRole('academic_lead');
        $this->teacher = $this->userWithRole('teacher');
        $this->assistant = $this->userWithRole('assistant');
        $this->gvnn = $this->userWithRole('teacher_parttime');
        $this->busyGvnn = $this->userWithRole('teacher_parttime');
        $this->otherTeacher = $this->userWithRole('teacher');

        $this->level = CourseLevel::create(['code' => 'N2-STARTERS', 'name' => 'Starters N2', 'target' => 'Pre-A1', 'is_active' => true]);
        $this->course = Course::create([
            'code' => 'N2-FAM1', 'name' => 'Starters FAM 1 (N2)', 'course_level_id' => $this->level->id,
            'tuition_fee' => 9000000, 'total_lessons' => 24, 'is_active' => true,
        ]);

        // Ngày nghỉ của chi nhánh nằm trong lịch lớp sẽ mở: buổi ngày đó không được sinh.
        $this->holiday = Holiday::create([
            'code' => 'N2-HOL', 'name' => 'Nghỉ cơ sở N2', 'start_date' => today()->addDays(7), 'end_date' => today()->addDays(7), 'is_system_wide' => false,
        ]);
        $this->holiday->branches()->sync([$this->branch->id]);

        // Lớp khác đang chiếm GVNN (today+2) và phòng P201 (today+4) cùng khung giờ.
        $this->otherClass = ClassModel::create([
            'code' => 'N2-OTHER', 'name' => 'Lớp đang chạy N2', 'branch_id' => $this->branch->id, 'course_id' => $this->course->id,
            'level' => $this->level->code, 'teacher_id' => $this->otherTeacher->id, 'foreign_teacher_id' => $this->busyGvnn->id,
            'room' => 'P900', 'max_capacity' => 10, 'status' => 'active',
        ]);
        ClassSession::create([
            'class_id' => $this->otherClass->id, 'branch_id' => $this->branch->id, 'date' => today()->addDays(2)->toDateString(),
            'shift_name' => 'Ca chiều', 'start_time' => '17:30', 'end_time' => '19:00', 'room' => 'P900',
            'teacher_id' => $this->otherTeacher->id, 'foreign_teacher_id' => $this->busyGvnn->id, 'status' => 'scheduled',
        ]);
        ClassSession::create([
            'class_id' => $this->otherClass->id, 'branch_id' => $this->branch->id, 'date' => today()->addDays(4)->toDateString(),
            'shift_name' => 'Ca chiều', 'start_time' => '17:00', 'end_time' => '18:00', 'room' => 'P201',
            'teacher_id' => $this->otherTeacher->id, 'status' => 'scheduled',
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /** Lịch lớp: buổi đã qua, hôm nay và sắp tới; today+7 là ngày nghỉ (sẽ bị bỏ). */
    private function scheduleJson(): string
    {
        $offsets = [-6, -4, -2, 0, 2, 4, 7, 9, 11, 14, 16, 18, 21, 23];

        return json_encode(array_map(fn (int $d) => [
            'date' => today()->addDays($d)->toDateString(), 'shift' => 'Ca chiều', 'start' => '17:30', 'end' => '19:00',
        ], $offsets));
    }

    private function classPayload(array $overrides = []): array
    {
        return $overrides + [
            'ten_lop' => 'Starters FAM 1 · K30 (N2)', 'ma_lop' => 'N2-K30', 'chi_nhanh' => $this->branch->id,
            'chuong_trinh' => $this->course->name, 'cap_do' => $this->level->code, 'si_so_toi_da' => 10, 'min_students' => 4,
            'phong_hoc' => 'P202', 'giao_vien_chinh' => $this->teacher->id, 'tro_giang' => $this->assistant->id,
            'giao_vien_nn' => $this->gvnn->id, 'hoc_phi' => 9000000, 'schedule_sessions_json' => $this->scheduleJson(),
        ];
    }

    /** Học thuật soạn giáo trình qua màn Soạn syllabus: 2 chặng, mỗi chặng 1 unit × 2 buổi, gắn Trình độ. */
    private function buildCurriculum(): SyllabusCurriculum
    {
        $this->actingAs($this->lead)->post(route('syllabus.curriculums.store'), [
            'code' => 'N2-CUR', 'title' => 'Starters N2', 'version' => 'v1', 'stage_name' => 'Chặng 1: Làm quen',
            'level_ids' => [$this->level->id],
        ])->assertSessionHasNoErrors();
        $curriculum = SyllabusCurriculum::where('code', 'N2-CUR')->firstOrFail();
        $this->assertSame($curriculum->id, $this->level->fresh()->syllabus_curriculum_id);

        $this->actingAs($this->lead)->post(route('syllabus.stages.store'), [
            'curriculum_id' => $curriculum->id, 'name' => 'Chặng 2: Giao tiếp', 'big_test_title' => 'Big Test chặng 2',
        ])->assertSessionHasNoErrors();

        $session = 1;
        foreach ($curriculum->stages()->orderBy('position')->get() as $i => $stage) {
            $this->actingAs($this->lead)->post(route('syllabus.units.store'), [
                'curriculum_id' => $curriculum->id, 'stage_id' => $stage->id, 'unit_number' => $i + 1, 'title' => 'Unit '.($i + 1),
            ])->assertSessionHasNoErrors();
            $unit = SyllabusUnit::where('curriculum_id', $curriculum->id)->where('unit_number', $i + 1)->firstOrFail();
            foreach ([1, 2] as $n) {
                $this->actingAs($this->lead)->post(route('syllabus.lessons.store'), [
                    'unit_id' => $unit->id, 'session_no' => $session, 'title' => "Buổi {$session}: nội dung",
                ])->assertSessionHasNoErrors();
                $session++;
            }
        }
        // Số buổi đánh liên tục trong cả giáo trình: trùng số bị chặn.
        $this->actingAs($this->lead)->post(route('syllabus.lessons.store'), ['unit_id' => $unit->id, 'session_no' => 1, 'title' => 'Trùng'])
            ->assertSessionHasErrors('session_no');
        $this->assertSame(4, SyllabusLesson::where('curriculum_id', $curriculum->id)->count());

        return $curriculum;
    }

    public function test_phase2_end_to_end_class_operations_flow(): void
    {
        $curriculum = $this->buildCurriculum();
        [$stage1, $stage2] = $curriculum->stages()->orderBy('position')->get()->all();

        // ── 1. Học vụ mở lớp: trùng GVNN / trùng phòng bị chặn, ngày nghỉ bị bỏ ──────────────
        $this->actingAs($this->academic)->post(route('classes.store'), $this->classPayload(['giao_vien_nn' => $this->busyGvnn->id]))
            ->assertSessionHasErrors('schedule_sessions_json');
        $this->actingAs($this->academic)->post(route('classes.store'), $this->classPayload(['phong_hoc' => 'P201']))
            ->assertSessionHasErrors('schedule_sessions_json');
        $this->assertFalse(ClassModel::where('code', 'N2-K30')->exists());

        $this->actingAs($this->academic)->post(route('classes.store'), $this->classPayload())->assertSessionHasNoErrors()->assertRedirect();
        $class = ClassModel::where('code', 'N2-K30')->firstOrFail();
        $this->assertSame('active', $class->status);
        $this->assertSame(13, $class->sessions()->count(), '14 buổi trừ 1 buổi rơi vào ngày nghỉ.');
        $this->assertFalse($class->sessions()->whereDate('date', $this->holiday->start_date)->exists());
        $this->assertSame(13, $class->sessions()->where('foreign_teacher_id', $this->gvnn->id)->where('assistant_id', $this->assistant->id)->count());

        // Ngày nghỉ thêm sau (Admin): buổi today+9 bị hủy và xếp 1 buổi học bù sau buổi cuối.
        $lastDate = $class->sessions()->max('date');
        $this->actingAs($this->admin)->post(route('holidays.store'), [
            'code' => 'N2-HOL2', 'name' => 'Nghỉ đột xuất', 'start_date' => today()->addDays(9)->toDateString(),
            'end_date' => today()->addDays(9)->toDateString(), 'branch_ids' => [$this->branch->id],
        ])->assertSessionHasNoErrors();
        $cancelled = $class->sessions()->whereDate('date', today()->addDays(9))->firstOrFail();
        $this->assertSame('cancelled', $cancelled->status);
        $makeup = ClassSession::where('rescheduled_from_id', $cancelled->id)->firstOrFail();
        $this->assertSame(ClassSession::TYPE_MAKEUP, $makeup->type);
        $this->assertTrue($makeup->date->gt($lastDate));

        // Dashboard lớp theo ngày dùng buổi học thật.
        $this->actingAs($this->academic)->get(route('tasks.classes-dashboard'))->assertOk()->assertSee($class->name);

        // Học viên của lớp (vào lớp ở Phase 1): 1 em chốt từ CRM có SĐT phụ huynh, 1 em có tài khoản cổng HV.
        $studentUser = $this->userWithRole('student');
        $s1 = $this->student('N2-HV1', 'An', '0911000001', $class, $studentUser);
        $s2 = $this->student('N2-HV2', 'Bình', '0911000002', $class);
        $s3 = $this->student('N2-HV3', 'Chi', '0911000003', $class);
        $s4 = $this->student('N2-HV4', 'Dũng', '0911000004', $class);
        CrmCustomer::create([
            'code' => 'KH-N2-1', 'name' => $s1->name, 'phone' => '0911000001', 'parent_name' => 'PH An', 'parent_phone' => '0987000111',
            'branch_id' => $this->branch->id, 'stage' => 'won', 'converted_student_id' => $s1->id,
        ]);

        // ── 2. Học thuật mở chặng 1 (giáo trình theo Trình độ); chặng thứ hai bị từ chối ─────────
        $this->actingAs($this->lead)->post(route('syllabus.assignments.store'), ['class_id' => $class->id])->assertSessionHasNoErrors();
        $open = SyllabusAssignment::open()->where('class_id', $class->id)->firstOrFail();
        $this->assertSame($stage1->id, $open->stage_id);
        $this->assertSame($this->teacher->id, $open->user_id);
        $this->actingAs($this->lead)->post(route('syllabus.assignments.store'), ['class_id' => $class->id, 'stage_id' => $stage2->id])
            ->assertSessionHasErrors('class_id');
        $this->assertSame(1, SyllabusAssignment::open()->where('class_id', $class->id)->count());
        $this->actingAs($this->teacher)->get(route('syllabus.teacher-view', ['class' => $class->id]))->assertOk();

        // ── 3. Giáo viên: lịch dạy hôm nay, điểm danh từng buổi, nhận xét, mini test ──────────
        $today = $class->sessions()->whereDate('date', today())->firstOrFail();
        $this->actingAs($this->teacher)->get(route('teacher.home'))->assertOk()
            ->assertViewHas('shifts', fn ($shifts) => $shifts->pluck('session.id')->contains($today->id));

        $this->actingAs($this->teacher)->post(route('teacher.attendance.store', $class->id), [
            'class_session_id' => $today->id,
            'status' => [$s1->id => 'present', $s2->id => 'absent', $s3->id => 'present', $s4->id => 'late'],
            'note' => [$s2->id => 'Ốm'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(4, StudentAttendance::where('class_session_id', $today->id)->count());
        $this->assertSame('completed', $today->fresh()->status);
        $absence = ClassReportStudentSupport::where('student_id', $s2->id)->where('source', SupportListService::SOURCE_ATTENDANCE)->firstOrFail();
        $this->assertSame($class->id, (int) $absence->class_id);

        // Điểm danh bù buổi đã qua; Học vụ điểm danh thay GV (user_id = GV của buổi, recorded_by = Học vụ).
        $past = $class->sessions()->whereDate('date', today()->subDays(2))->firstOrFail();
        $this->actingAs($this->academic)->post(route('teacher.attendance.store', $class->id), [
            'class_session_id' => $past->id,
            'status' => [$s1->id => 'present', $s2->id => 'present', $s3->id => 'present', $s4->id => 'present'],
        ])->assertSessionHasNoErrors();
        $proxy = StudentAttendance::where('class_session_id', $past->id)->where('student_id', $s1->id)->firstOrFail();
        $this->assertSame($this->teacher->id, (int) $proxy->user_id);
        $this->assertSame($this->academic->id, (int) $proxy->recorded_by);
        // Buổi chưa tới ngày thì chưa điểm danh được.
        $future = $class->sessions()->whereDate('date', today()->addDays(2))->firstOrFail();
        $this->actingAs($this->teacher)->post(route('teacher.attendance.store', $class->id), [
            'class_session_id' => $future->id, 'status' => [$s1->id => 'present'],
        ])->assertSessionHasErrors('session');

        $this->actingAs($this->teacher)->post(route('teacher.remarks.store', $class->id), [
            'remarks' => [$s1->id => ['grammar' => 'Tốt', 'attitude' => 'Hăng hái', 'result' => 'Đạt', 'comment' => 'Phát âm rõ.']],
        ])->assertSessionHasNoErrors()->assertRedirect(route('teacher.remarks', ['classId' => $class->id, 'session' => $today->id]));

        $this->actingAs($this->teacher)->post(route('teacher.scores.store', $class->id), [
            'name' => 'Mini Test Unit 1', 'test_date' => today()->toDateString(), 'max_score' => 20,
            'score' => [$s1->id => 18, $s3->id => 12, $s4->id => 15],
        ])->assertRedirect(route('teacher.home'));
        $this->assertSame(3, MiniTestScore::where('class_id', $class->id)->count());
        $this->assertTrue(ClassReportStudentSupport::where('student_id', $s3->id)->where('source', SupportListService::SOURCE_MINI_TEST)->exists(), '12/20 = 6/10 < 7');
        $this->assertFalse(ClassReportStudentSupport::where('student_id', $s4->id)->where('source', SupportListService::SOURCE_MINI_TEST)->exists(), '15/20 = 7.5/10');

        // Giáo viên không thao tác được lớp khác.
        $this->actingAs($this->teacher)->get(route('teacher.attendance', $this->otherClass->id))->assertForbidden();
        $this->actingAs($this->teacher)->post(route('teacher.scores.store', $this->otherClass->id), [
            'name' => 'X', 'test_date' => today()->toDateString(), 'max_score' => 10, 'score' => [],
        ])->assertForbidden();
        $this->actingAs($this->otherTeacher)->get(route('teacher.attendance', ['classId' => $class->id, 'session' => $today->id]))->assertForbidden();

        // ── 4. Học vụ xếp buổi bổ trợ từ danh sách bổ trợ ────────────────────────────────────
        $this->actingAs($this->academic)->get(route('tasks.support-sessions'))->assertOk()
            ->assertViewHas('pendingSupports', fn ($rows) => $rows->pluck('id')->contains($absence->id));
        $this->actingAs($this->academic)->post(route('tasks.support-sessions.store'), [
            'class_report_student_support_id' => $absence->id, 'class_id' => $class->id, 'student_id' => $s2->id,
            'teacher_id' => $this->assistant->id, 'session_date' => today()->addDay()->toDateString(),
            'start_time' => '09:00', 'end_time' => '10:00', 'room' => 'P105',
        ])->assertSessionHasNoErrors();
        $support = SupportSession::where('class_report_student_support_id', $absence->id)->firstOrFail();
        $this->assertSame(ClassSession::TYPE_SUPPORT, $support->classSession->type);
        $this->assertTrue(ClassReportStudentSupport::whereKey($absence->id)->whereHas('supportSession')->exists());

        // ── 5. Big Test: GV order đề → Học thuật tạo đợt thi + duyệt order kèm link → nhắc lịch 7 ngày ─
        $examAt = today()->addDays(5)->setTime(17, 30);
        $this->actingAs($this->teacher)->post(route('teacher.order-test.submit', $class->id), [
            'stage_name' => $stage1->label, 'test_type' => 'big', 'exam_date' => $examAt->toDateString(), 'note' => 'Đề cuối chặng 1',
        ])->assertSessionHasNoErrors();
        $order = BigTestOrder::where('class_id', $class->id)->firstOrFail();
        $this->assertSame('pending', $order->status);
        $this->assertSame($examAt->copy()->subDays(BigTestOrder::LEAD_DAYS)->toDateString(), $order->due_date->toDateString());
        $this->actingAs($this->teacher)->post(route('syllabus.big-tests.orders.approve', $order->id), ['test_link' => 'https://drive.test/de'])->assertForbidden();

        $this->actingAs($this->lead)->post(route('syllabus.big-tests.store'), [
            'title' => 'Big Test chặng 1', 'class_id' => $class->id, 'test_type' => 'stage_end', 'scheduled_at' => $examAt->format('Y-m-d H:i'), 'room' => 'P202',
        ])->assertSessionHasNoErrors();
        $bigTest = BigTest::where('class_id', $class->id)->firstOrFail();
        $this->assertSame($stage1->id, (int) $bigTest->syllabus_stage_id, 'Big Test tự gắn chặng đang mở.');
        $this->assertFalse($bigTest->is_distributed);

        $this->actingAs($this->lead)->post(route('syllabus.big-tests.orders.approve', $order->id), [
            'test_link' => 'https://drive.test/de-chang-1', 'big_test_id' => $bigTest->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame('approved', $order->fresh()->status);
        $bigTest->refresh();
        $this->assertTrue($bigTest->is_distributed, 'Duyệt & phân phối order gắn đợt thi thì đợt thi được phân phối.');
        $this->assertSame('https://drive.test/de-chang-1', $bigTest->content_url);
        $this->assertTrue(AdminNotification::where('user_id', $this->teacher->id)->where('title', 'Order đề đã được duyệt')->exists());

        $this->artisan('bigtests:remind-upcoming')->assertSuccessful();
        foreach ([$this->teacher, $this->gvnn, $this->assistant] as $staff) {
            $this->assertSame(1, AdminNotification::where('user_id', $staff->id)->where('type', 'big_test_upcoming')->count());
        }
        $this->artisan('bigtests:remind-upcoming')->assertSuccessful();
        $this->assertSame(1, AdminNotification::where('user_id', $this->teacher->id)->where('type', 'big_test_upcoming')->count(), 'Mỗi đợt thi nhắc 1 lần.');

        // ── 6. GV nhập kết quả (vắng thi để trống điểm) → Học thuật duyệt → khóa sửa ────────────
        $this->actingAs($this->teacher)->get(route('syllabus.big-tests.results', $bigTest->id))->assertOk();
        $this->actingAs($this->otherTeacher)->get(route('syllabus.big-tests.results', $bigTest->id))->assertNotFound();
        $this->actingAs($this->otherTeacher)->post(route('syllabus.big-tests.results.store', $bigTest->id), [
            'results' => [['student_id' => $s1->id, 'listening_score' => 1, 'reading_score' => 1, 'writing_score' => 1, 'speaking_score' => 1]],
        ])->assertNotFound();

        $this->actingAs($this->teacher)->post(route('syllabus.big-tests.results.store', $bigTest->id), ['results' => [
            ['student_id' => $s1->id, 'listening_score' => 8, 'reading_score' => 8.5, 'writing_score' => 7.5, 'speaking_score' => 8, 'progress_note' => 'Tiến bộ tốt'],
            ['student_id' => $s2->id, 'is_absent' => 1],
            ['student_id' => $s3->id, 'listening_score' => 6, 'reading_score' => 6, 'writing_score' => 6.5, 'speaking_score' => 6],
            ['student_id' => $s4->id, 'listening_score' => 7, 'reading_score' => 7.5, 'writing_score' => 7, 'speaking_score' => 8],
        ]])->assertSessionHasNoErrors();
        $absent = BigTestResult::where('big_test_id', $bigTest->id)->where('student_id', $s2->id)->firstOrFail();
        $this->assertTrue($absent->is_absent);
        $this->assertNull($absent->overall_score, 'Vắng thi không lưu điểm 0.');
        $this->assertSame(4, BigTestResult::where('big_test_id', $bigTest->id)->where('status', 'pending_review')->count());
        $this->assertTrue(ClassReportStudentSupport::where('student_id', $s3->id)->where('source', SupportListService::SOURCE_BIG_TEST)->exists());
        // Kết quả chưa duyệt không hiện ở cổng học viên.
        $this->actingAs($studentUser)->get(route('portal.student.home'))->assertOk()
            ->assertViewHas('bigTestResults', fn ($results) => $results->isEmpty());

        $this->actingAs($this->teacher)->post(route('syllabus.big-tests.results.approve', $bigTest->id))->assertForbidden();
        $this->actingAs($this->lead)->post(route('syllabus.big-tests.results.approve', $bigTest->id))->assertSessionHasNoErrors();
        $this->assertSame(4, BigTestResult::where('big_test_id', $bigTest->id)->where('status', 'approved')->count());
        $this->assertTrue(SyllabusAssignment::open()->where('class_id', $class->id)->where('stage_id', $stage1->id)->exists(), 'Chưa gửi PH thì chặng chưa đóng.');

        $this->actingAs($this->teacher)->post(route('syllabus.big-tests.results.store', $bigTest->id), ['results' => [
            ['student_id' => $s1->id, 'listening_score' => 10, 'reading_score' => 10, 'writing_score' => 10, 'speaking_score' => 10],
        ]])->assertSessionHas('error');
        $this->assertSame('8.0', (string) BigTestResult::where('big_test_id', $bigTest->id)->where('student_id', $s1->id)->value('listening_score'));

        // ── 7. Gửi Zalo ZNS cho phụ huynh (chế độ live, Zalo API giả lập bằng Http::fake) ─────────
        config(['services.zalo.mode' => 'live', 'services.zalo.access_token' => 'test-token']);
        $failPhone = '84911000004';
        Http::fake(['business.openapi.zalo.me/*' => function (HttpRequest $request) use (&$failPhone) {
            return $request['phone'] === $failPhone
                ? Http::response(['error' => -124, 'message' => 'Số điện thoại không hợp lệ'])
                : Http::response(['error' => 0, 'message' => 'Success', 'data' => ['msg_id' => 'm-'.$request['phone']]]);
        }]);

        $this->actingAs($this->lead)->post(route('syllabus.big-tests.send-zalo', $bigTest->id))->assertSessionHas('warning');
        Http::assertSentCount(3);
        Http::assertSent(fn (HttpRequest $r) => $r['phone'] === '84987000111' && $r->hasHeader('access_token', 'test-token')
            && $r['template_data']['overall_score'] === '8.0');
        Http::assertNotSent(fn (HttpRequest $r) => $r['phone'] === '84911000002');
        $this->assertTrue(BigTestResult::where('big_test_id', $bigTest->id)->where('student_id', $s1->id)->where('status', 'sent')->where('parent_notified', true)->exists());
        $this->assertSame('approved', BigTestResult::where('big_test_id', $bigTest->id)->where('student_id', $s4->id)->value('status'), 'Gửi lỗi không đánh dấu đã gửi.');
        $this->assertTrue(SyllabusAssignment::open()->where('class_id', $class->id)->where('stage_id', $stage1->id)->exists());

        // Gửi lại: chỉ gửi kết quả còn thiếu (không gửi lại cả lớp) → chặng 1 đóng, chặng 2 tự mở.
        $failPhone = null;
        $this->actingAs($this->lead)->post(route('syllabus.big-tests.send-zalo', $bigTest->id))->assertSessionHas('status');
        Http::assertSentCount(4);
        $this->actingAs($this->lead)->post(route('syllabus.big-tests.send-zalo', $bigTest->id))->assertSessionHas('error');
        Http::assertSentCount(4);

        $closed = SyllabusAssignment::where('class_id', $class->id)->where('stage_id', $stage1->id)->firstOrFail();
        $this->assertSame(SyllabusAssignment::STATUS_CLOSED, $closed->status);
        $this->assertSame($bigTest->id, (int) $closed->closed_by_big_test_id);
        $next = SyllabusAssignment::open()->where('class_id', $class->id)->firstOrFail();
        $this->assertSame($stage2->id, $next->stage_id);
        $this->assertSame($this->teacher->id, $next->user_id);
        $this->assertTrue(AdminNotification::where('user_id', $this->teacher->id)->where('type', 'syllabus_stage')->where('title', 'like', '%'.$stage2->name.'%')->exists());
        $this->assertNotNull($bigTest->fresh()->results_completed_at);

        // Kết quả đã gửi cũng không sửa được.
        $this->actingAs($this->teacher)->post(route('syllabus.big-tests.results.store', $bigTest->id), ['results' => [
            ['student_id' => $s4->id, 'listening_score' => 1, 'reading_score' => 1, 'writing_score' => 1, 'speaking_score' => 1],
        ]])->assertSessionHas('error');

        // ── 8. Cổng học viên: lịch học, điểm danh, kết quả thi của chính mình ────────────────
        $this->actingAs($studentUser)->get(route('portal.student.home'))->assertOk()
            ->assertViewHas('student', fn ($student) => $student->id === $s1->id)
            ->assertViewHas('upcomingSessions', fn ($sessions) => $sessions->isNotEmpty() && $sessions->every(fn ($s) => (int) $s->class_id === $class->id))
            ->assertViewHas('attendanceHistory', fn ($rows) => $rows->count() === 2 && $rows->every(fn ($a) => (int) $a->student_id === $s1->id))
            ->assertViewHas('bigTestResults', fn ($results) => $results->count() === 1 && $results->first()->status === 'sent');
        $this->actingAs($studentUser)->get(route('portal.student.home', ['studentId' => $s2->id]))->assertForbidden();
        $this->actingAs($studentUser)->get(route('portal.student.homework'))->assertOk()
            ->assertViewHas('miniTests', fn ($scores) => $scores->count() === 1)
            ->assertViewHas('remarks', fn ($remarks) => $remarks->count() === 1 && $remarks->first()['remark']['grammar'] === 'Tốt');

        // Chấm công tay: Quản lý cơ sở không chấm cho lớp ngoài chi nhánh mình (Học vụ hiện xem mọi lớp — chờ BA).
        $manager = $this->userWithRole('manager');
        $farBranch = Branch::create(['name' => 'Cơ sở xa', 'code' => 'FAR', 'is_active' => true]);
        $farClass = ClassModel::create(['code' => 'FAR-01', 'name' => 'Lớp xa', 'branch_id' => $farBranch->id, 'teacher_id' => $this->otherTeacher->id, 'status' => 'active']);
        $this->actingAs($manager)->post(route('payroll.timesheets.manual.store'), [
            'user_id' => $this->otherTeacher->id, 'class_id' => $farClass->id, 'teaching_date' => today()->subDay()->toDateString(),
            'time_in' => '18:00', 'time_out' => '19:30', 'type' => 'regular', 'notes' => 'Quên check-in buổi tối',
        ])->assertForbidden();
        $this->assertFalse(TeacherTimesheet::where('class_id', $farClass->id)->exists());
    }

    private function student(string $code, string $name, string $phone, ClassModel $class, ?User $user = null): Student
    {
        return Student::create([
            'code' => $code, 'name' => 'HV '.$name, 'phone' => $phone, 'branch_id' => $this->branch->id,
            'current_class_id' => $class->id, 'status' => 'studying', 'user_id' => $user?->id,
        ]);
    }
}
