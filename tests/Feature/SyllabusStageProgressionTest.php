<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\BigTest;
use App\Models\BigTestResult;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassScheduleConfig;
use App\Models\ClassSession;
use App\Models\CourseLevel;
use App\Models\Student;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Models\SyllabusUnit;
use App\Models\User;
use App\Services\SyllabusProgressionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Q4 — Giáo trình → Chặng → Unit → Buổi; mỗi lớp 1 chặng mở; chặng đóng khi Big Test duyệt & gửi PH,
 * chặng kế tiếp tự mở; Học thuật mở/đóng tay có lý do.
 */
class SyllabusStageProgressionTest extends TestCase
{
    use RefreshDatabase;

    private User $academic;

    private User $teacher;

    private ClassModel $class;

    private SyllabusCurriculum $curriculum;

    /** @var array<int, SyllabusStage> */
    private array $stages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        config(['services.zalo.mode' => 'sandbox']);

        $branch = Branch::create(['name' => 'Cơ sở GT', 'code' => 'GT', 'is_active' => true]);
        $this->academic = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $this->academic->assignRole('academic_lead');
        $this->teacher = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $this->teacher->assignRole('teacher');

        $this->curriculum = SyllabusCurriculum::create(['code' => 'CUR-Q4', 'title' => 'Kids Q4', 'version' => 'v1', 'stage_name' => 'Chặng 1: Nền tảng']);
        $this->stages = [
            $this->curriculum->stages()->firstOrFail(),
            SyllabusStage::create(['curriculum_id' => $this->curriculum->id, 'position' => 2, 'name' => 'Chặng 2: Giao tiếp', 'big_test_title' => 'Big Test 2']),
            SyllabusStage::create(['curriculum_id' => $this->curriculum->id, 'position' => 3, 'name' => 'Chặng 3: Luyện đề']),
        ];
        $session = 1;
        foreach ($this->stages as $i => $stage) {
            $unit = SyllabusUnit::create(['curriculum_id' => $this->curriculum->id, 'stage_id' => $stage->id, 'unit_number' => $i + 1, 'title' => 'Unit '.($i + 1)]);
            foreach (range(1, 3) as $n) {
                SyllabusLesson::create(['unit_id' => $unit->id, 'session_no' => $session, 'title' => "Nội dung buổi {$session}"]);
                $session++;
            }
        }

        CourseLevel::create(['code' => 'KQ4', 'name' => 'Kids Q4', 'target' => 'A1', 'syllabus_curriculum_id' => $this->curriculum->id, 'is_active' => true]);
        $this->class = ClassModel::create([
            'code' => 'GT-01', 'name' => 'Lớp GT-01', 'branch_id' => $branch->id, 'level' => 'KQ4',
            'teacher_id' => $this->teacher->id, 'status' => 'active',
        ]);
    }

    private function openFirstStage(): SyllabusAssignment
    {
        $this->actingAs($this->academic)->post(route('syllabus.assignments.store'), ['class_id' => $this->class->id])
            ->assertSessionHasNoErrors();

        return SyllabusAssignment::open()->where('class_id', $this->class->id)->firstOrFail();
    }

    private function student(string $code): Student
    {
        return Student::create(['code' => $code, 'name' => 'HV '.$code, 'phone' => '0912345678', 'parent_phone' => '0987123456', 'current_class_id' => $this->class->id, 'status' => 'studying']);
    }

    private function bigTestFor(SyllabusStage $stage, string $code = 'BT-Q4-1'): BigTest
    {
        return BigTest::create([
            'code' => $code, 'title' => 'Big Test '.$code, 'class_id' => $this->class->id, 'syllabus_stage_id' => $stage->id,
            'test_type' => 'midterm', 'scheduled_at' => now()->subDay(), 'room' => 'Lab', 'is_distributed' => true, 'status' => 'distributed',
        ]);
    }

    private function makeResult(BigTest $test, Student $student, string $status, bool $absent = false): BigTestResult
    {
        return BigTestResult::create([
            'big_test_id' => $test->id, 'student_id' => $student->id, 'is_absent' => $absent, 'status' => $status,
            'listening_score' => $absent ? null : 7, 'reading_score' => $absent ? null : 7,
            'writing_score' => $absent ? null : 7, 'speaking_score' => $absent ? null : 7, 'overall_score' => $absent ? null : 7,
        ]);
    }

    // ---- Mở chặng ----

    public function test_opening_defaults_to_level_curriculum_first_stage_and_class_teacher(): void
    {
        $assignment = $this->openFirstStage();

        $this->assertSame($this->stages[0]->id, $assignment->stage_id);
        $this->assertSame($this->curriculum->id, $assignment->curriculum_id);
        $this->assertSame($this->teacher->id, $assignment->user_id);
        $this->assertSame('Chặng 1: Nền tảng', $assignment->stage_name);
        $this->assertSame('Unit 1 · Buổi 1–3', $assignment->assigned_chapters);
        $this->assertNotNull($assignment->opened_at);
        $this->assertSame($this->class->id, $assignment->open_class_id);
        $this->assertTrue(AdminNotification::where('user_id', $this->teacher->id)->where('type', 'syllabus_stage')->exists());
    }

    public function test_class_can_have_only_one_open_stage(): void
    {
        $this->openFirstStage();

        $this->actingAs($this->academic)->post(route('syllabus.assignments.store'), ['class_id' => $this->class->id, 'stage_id' => $this->stages[1]->id])
            ->assertSessionHasErrors('class_id');
        $this->assertSame(1, SyllabusAssignment::open()->where('class_id', $this->class->id)->count());

        // DB cũng chặn khi ghi thẳng (UNIQUE open_class_id).
        $this->expectException(QueryException::class);
        SyllabusAssignment::create([
            'user_id' => $this->teacher->id, 'curriculum_id' => $this->curriculum->id, 'stage_id' => $this->stages[1]->id,
            'class_id' => $this->class->id, 'assigned_chapters' => 'x', 'status' => 'in_progress',
        ]);
    }

    public function test_stage_from_other_curriculum_is_rejected(): void
    {
        $other = SyllabusCurriculum::create(['code' => 'CUR-X', 'title' => 'Khác', 'version' => 'v1']);
        $this->actingAs($this->academic)->post(route('syllabus.assignments.store'), [
            'class_id' => $this->class->id, 'curriculum_id' => $this->curriculum->id, 'stage_id' => $other->stages()->value('id'),
        ])->assertSessionHasErrors('stage_id');
    }

    // ---- Đóng chặng theo Big Test ----

    public function test_stage_closes_and_next_opens_only_when_big_test_is_approved_and_sent(): void
    {
        $first = $this->openFirstStage();
        $a = $this->student('HV-Q4-1');
        $b = $this->student('HV-Q4-2');
        $absent = $this->student('HV-Q4-3');

        // Big Test tạo mới tự gắn chặng đang mở.
        $this->actingAs($this->academic)->post(route('syllabus.big-tests.store'), [
            'title' => 'Big Test chặng 1', 'class_id' => $this->class->id, 'test_type' => 'midterm',
            'scheduled_at' => now()->subDay()->format('Y-m-d H:i'), 'room' => 'Lab',
        ])->assertSessionHasNoErrors();
        $test = BigTest::where('title', 'Big Test chặng 1')->firstOrFail();
        $this->assertSame($this->stages[0]->id, $test->syllabus_stage_id);
        $test->update(['is_distributed' => true, 'status' => 'distributed']);

        $this->makeResult($test, $a, 'pending_review');
        $this->makeResult($test, $b, 'pending_review');
        $this->makeResult($test, $absent, 'pending_review', true);

        // Duyệt chưa đủ: chưa gửi PH.
        $this->actingAs($this->academic)->post(route('syllabus.big-tests.results.approve', $test->id))->assertRedirect();
        $this->assertTrue($first->fresh()->isOpen());

        // Gửi PH 1 học viên: vẫn còn 1 kết quả chưa gửi.
        $resultA = BigTestResult::where('student_id', $a->id)->firstOrFail();
        $this->actingAs($this->academic)->post(route('syllabus.big-tests.send-single-zalo', $resultA->id))->assertRedirect();
        $this->assertTrue($resultA->fresh()->parent_notified);
        $this->assertTrue($first->fresh()->isOpen());

        // Gửi phần còn lại → chặng 1 đóng, chặng 2 tự mở, GV được báo.
        $this->actingAs($this->academic)->post(route('syllabus.big-tests.send-zalo', $test->id))
            ->assertRedirect()->assertSessionHas('status', fn ($m) => str_contains($m, 'tự mở Chặng 2: Giao tiếp'));

        $first->refresh();
        $this->assertSame('completed', $first->status);
        $this->assertSame($test->id, $first->closed_by_big_test_id);
        $this->assertNotNull($first->closed_at);
        $this->assertNull($first->open_class_id);
        $this->assertNotNull($test->fresh()->results_completed_at);

        $next = SyllabusAssignment::open()->where('class_id', $this->class->id)->firstOrFail();
        $this->assertSame($this->stages[1]->id, $next->stage_id);
        $this->assertSame($this->teacher->id, $next->user_id);
        $this->assertStringContainsString($test->code, (string) $next->open_reason);
        $this->assertTrue(AdminNotification::where('user_id', $this->teacher->id)->where('title', 'like', '%Chặng 2: Giao tiếp%')->exists());
    }

    public function test_big_test_of_another_stage_does_not_close_current_stage(): void
    {
        $this->openFirstStage();
        $test = $this->bigTestFor($this->stages[1], 'BT-OTHER');
        $this->makeResult($test, $this->student('HV-O1'), 'approved');
        $this->actingAs($this->academic)->post(route('syllabus.big-tests.send-zalo', $test->id))->assertRedirect();

        $open = SyllabusAssignment::open()->where('class_id', $this->class->id)->firstOrFail();
        $this->assertSame($this->stages[0]->id, $open->stage_id);
    }

    public function test_closing_last_stage_marks_curriculum_finished(): void
    {
        $service = app(SyllabusProgressionService::class);
        $last = $service->open($this->class, $this->stages[2], null, $this->academic, 'Chuyển lớp từ cơ sở khác');
        $test = $this->bigTestFor($this->stages[2], 'BT-LAST');
        $this->makeResult($test, $this->student('HV-L1'), 'sent');
        $this->makeResult($test, $this->student('HV-L2'), 'approved', true);

        $outcome = $service->syncBigTest($test, $this->academic);

        $this->assertNotNull($outcome);
        $this->assertTrue($outcome['finished']);
        $this->assertNull($outcome['next']);
        $this->assertNotNull($last->fresh()->curriculum_completed_at);
        $this->assertFalse(SyllabusAssignment::open()->where('class_id', $this->class->id)->exists());
        $this->assertTrue($service->curriculumFinished($this->class));

        // Lớp đã học hết: không còn chặng kế tiếp để mở.
        $this->actingAs($this->academic)->post(route('syllabus.assignments.store'), ['class_id' => $this->class->id])
            ->assertSessionHasErrors('stage_id');
    }

    // ---- Mở/đóng tay (Học thuật) ----

    public function test_manual_close_requires_reason_and_academic_permission(): void
    {
        $first = $this->openFirstStage();

        $this->actingAs($this->teacher)->post(route('syllabus.assignments.close', $first->id), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->academic)->post(route('syllabus.assignments.close', $first->id))->assertSessionHasErrors('reason');

        $this->actingAs($this->academic)->get(route('syllabus.assignments'))
            ->assertOk()->assertSee('Lớp GT-01')->assertSee('Đang học')->assertSee('Đóng tay');

        $this->actingAs($this->academic)->post(route('syllabus.assignments.close', $first->id), ['reason' => 'Thi bù ở lớp khác', 'open_next' => '1'])
            ->assertRedirect();
        $first->refresh();
        $this->assertSame('completed', $first->status);
        $this->assertSame($this->academic->id, $first->closed_by);
        $this->assertStringContainsString('Thi bù ở lớp khác', $first->close_reason);
        $this->assertSame($this->stages[1]->id, SyllabusAssignment::open()->where('class_id', $this->class->id)->value('stage_id'));
    }

    public function test_academic_can_switch_open_stage_with_reason(): void
    {
        $first = $this->openFirstStage();
        $payload = ['class_id' => $this->class->id, 'stage_id' => $this->stages[2]->id, 'replace_current' => '1'];

        $this->actingAs($this->academic)->post(route('syllabus.assignments.store'), $payload)->assertSessionHasErrors('reason');
        $this->assertTrue($first->fresh()->isOpen());

        $this->actingAs($this->academic)->post(route('syllabus.assignments.store'), $payload + ['reason' => 'Học viên đã học chặng 1-2'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($first->fresh()->isOpen());
        $open = SyllabusAssignment::open()->where('class_id', $this->class->id)->firstOrFail();
        $this->assertSame($this->stages[2]->id, $open->stage_id);
        $this->assertSame('Học viên đã học chặng 1-2', $open->open_reason);
    }

    // ---- Màn GV & giãn tiến độ ----

    public function test_teacher_sees_current_stage_and_next_session(): void
    {
        $assignment = $this->openFirstStage();
        $assignment->update(['opened_at' => now()->subDays(10)]);
        foreach ([8, 5] as $daysAgo) {
            ClassSession::create([
                'class_id' => $this->class->id, 'branch_id' => $this->class->branch_id, 'date' => now()->subDays($daysAgo)->toDateString(),
                'shift_name' => 'Ca', 'start_time' => '18:00', 'end_time' => '19:30', 'teacher_id' => $this->teacher->id, 'status' => 'completed',
            ]);
        }

        $this->actingAs($this->teacher)->get(route('syllabus.teacher-view'))
            ->assertOk()
            ->assertSee('Chặng 1: Nền tảng')
            ->assertSee('Đã dạy 2 / 3 buổi của chặng')
            ->assertSee('Buổi tiếp theo: Buổi 3')
            ->assertSee('Nội dung buổi 3')
            ->assertDontSee('Nội dung buổi 4');

        // GV khác không xem được lớp này.
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('teacher');
        $this->actingAs($other)->get(route('syllabus.teacher-view', ['class' => $this->class->id]))->assertNotFound();
    }

    public function test_approved_extension_is_recorded_on_open_stage(): void
    {
        $assignment = $this->openFirstStage();
        ClassScheduleConfig::create([
            'class_id' => $this->class->id, 'academic_year' => '2026',
            'slot1_day' => 'Thứ 2', 'slot1_start' => '18:00', 'slot1_end' => '19:30',
        ]);
        ClassSession::create([
            'class_id' => $this->class->id, 'branch_id' => $this->class->branch_id, 'date' => now()->next('Monday')->toDateString(),
            'shift_name' => 'Ca', 'start_time' => '18:00', 'end_time' => '19:30', 'teacher_id' => $this->teacher->id, 'status' => 'scheduled',
        ]);

        $this->actingAs($this->teacher)->post(route('syllabus.adjustment-requests.store'), [
            'class_id' => $this->class->id, 'request_type' => 'Giãn tiến độ', 'reason' => 'Lớp chậm', 'extra_sessions' => 2,
        ])->assertRedirect();
        $req = SyllabusAdjustmentRequest::firstOrFail();
        $this->assertSame($assignment->id, $req->syllabus_assignment_id);

        $this->actingAs($this->academic)->post(route('syllabus.adjustment-requests.approve', $req->id))->assertSessionHasNoErrors();
        $this->assertSame(2, $assignment->fresh()->extra_sessions);
        $this->assertStringContainsString('+2 buổi giãn tiến độ', $req->fresh()->applied_note);
    }

    // ---- Soạn syllabus ----

    public function test_builder_manages_stages_units_and_sessions_with_single_numbering(): void
    {
        $this->actingAs($this->academic)->get(route('syllabus.builder', ['curriculum' => $this->curriculum->id]))
            ->assertOk()->assertSee('Chặng 2: Giao tiếp')->assertSee('Unit 2: Unit 2', false)->assertSee('Buổi 4: Nội dung buổi 4');

        $this->actingAs($this->academic)->post(route('syllabus.stages.store'), [
            'curriculum_id' => $this->curriculum->id, 'name' => 'Chặng 4: Tổng ôn', 'big_test_title' => 'Final',
        ])->assertSessionHasNoErrors();
        $stage4 = SyllabusStage::where('name', 'Chặng 4: Tổng ôn')->firstOrFail();
        $this->assertSame(4, $stage4->position);

        // Đổi thứ tự: chặng 4 lên trước chặng 3.
        $this->actingAs($this->academic)->post(route('syllabus.stages.move', $stage4->id), ['direction' => 'up'])->assertRedirect();
        $this->assertSame(3, $stage4->fresh()->position);
        $this->assertSame(4, $this->stages[2]->fresh()->position);
        $this->assertSame($stage4->id, $this->stages[1]->fresh()->next()->id);

        // Unit + buổi mới: số buổi trùng trong giáo trình bị chặn (dù khác unit).
        $this->actingAs($this->academic)->post(route('syllabus.units.store'), [
            'curriculum_id' => $this->curriculum->id, 'stage_id' => $stage4->id, 'unit_number' => 4, 'title' => 'Review',
        ])->assertSessionHasNoErrors();
        $unit4 = SyllabusUnit::where('title', 'Review')->firstOrFail();
        $this->assertSame($stage4->id, $unit4->stage_id);

        $this->actingAs($this->academic)->post(route('syllabus.lessons.store'), [
            'unit_id' => $unit4->id, 'session_no' => 2, 'title' => 'Trùng',
        ])->assertSessionHasErrors('session_no');
        $this->actingAs($this->academic)->post(route('syllabus.lessons.store'), [
            'unit_id' => $unit4->id, 'session_no' => 10, 'title' => 'Ôn tập tổng hợp', 'objectives' => 'Ôn 4 kỹ năng',
        ])->assertSessionHasNoErrors();
        $lesson = SyllabusLesson::where('session_no', 10)->firstOrFail();
        $this->assertSame($this->curriculum->id, $lesson->curriculum_id);

        // Các ô soạn thảo hiển thị đúng.
        foreach ([['new_stage' => 1], ['edit_stage' => $stage4->id], ['new_unit' => $stage4->id], ['edit_unit' => $unit4->id], ['new_lesson' => $unit4->id], ['edit_lesson' => $lesson->id]] as $params) {
            $this->actingAs($this->academic)->get(route('syllabus.builder', ['curriculum' => $this->curriculum->id] + $params))
                ->assertOk()->assertSee('id="editor"', false);
        }
        $this->actingAs($this->academic)->get(route('syllabus.builder', ['curriculum' => $this->curriculum->id, 'edit_lesson' => $lesson->id]))
            ->assertSee('Sửa Buổi 10: Ôn tập tổng hợp');

        $this->actingAs($this->academic)->put(route('syllabus.lessons.update', $lesson->id), [
            'session_no' => 11, 'title' => 'Ôn tập (sửa)', 'unit_id' => $unit4->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame(11, $lesson->fresh()->session_no);

        // Chặng còn unit không xóa được; xóa unit thì xóa (mềm) luôn buổi.
        $this->actingAs($this->academic)->delete(route('syllabus.stages.destroy', $stage4->id))->assertSessionHas('error');
        $this->actingAs($this->academic)->delete(route('syllabus.units.destroy', $unit4->id))->assertRedirect();
        $this->assertSoftDeleted('syllabus_lessons', ['id' => $lesson->id]);
        // Buổi đã xóa mềm nhả số buổi cho buổi mới cùng số.
        $this->actingAs($this->academic)->post(route('syllabus.lessons.store'), [
            'unit_id' => SyllabusUnit::where('curriculum_id', $this->curriculum->id)->firstOrFail()->id, 'session_no' => 11, 'title' => 'Dùng lại số 11',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, SyllabusLesson::where('curriculum_id', $this->curriculum->id)->where('session_no', 11)->count());
        $this->actingAs($this->academic)->delete(route('syllabus.stages.destroy', $stage4->id))->assertSessionHas('status');
        $this->assertSame(3, $this->stages[2]->fresh()->position);

        // Teacher không soạn được.
        $this->actingAs($this->teacher)->post(route('syllabus.stages.store'), ['curriculum_id' => $this->curriculum->id, 'name' => 'X'])->assertForbidden();
    }

    public function test_curriculum_links_to_levels_from_builder(): void
    {
        $other = CourseLevel::create(['code' => 'KQ5', 'name' => 'Kids Q5', 'target' => 'A2', 'is_active' => true]);
        $this->actingAs($this->academic)->put(route('syllabus.curriculums.update', $this->curriculum->id), [
            'code' => 'CUR-Q4', 'title' => 'Kids Q4', 'version' => 'v1', 'levels_submitted' => '1', 'level_ids' => [$other->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->curriculum->id, $other->fresh()->syllabus_curriculum_id);
        $this->assertNull(CourseLevel::where('code', 'KQ4')->value('syllabus_curriculum_id'));
    }
}
