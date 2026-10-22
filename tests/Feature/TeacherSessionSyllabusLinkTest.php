<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusCurriculum;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Lối tắt "Mở giáo trình" từ buổi dạy (lịch dạy + điểm danh) sang màn GV xem bài giảng của lớp,
 * chỉ khi lớp đang có chặng mở.
 */
class TeacherSessionSyllabusLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private ClassModel $class;

    private ClassSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 10:00:00');
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $branch = Branch::create(['name' => 'Cơ sở GT', 'code' => 'GT-SL', 'is_active' => true]);
        $this->teacher = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $this->teacher->assignRole('teacher');
        $this->class = ClassModel::create([
            'code' => 'SL-01', 'name' => 'Lớp SL-01', 'branch_id' => $branch->id,
            'teacher_id' => $this->teacher->id, 'status' => 'active',
        ]);
        $this->session = ClassSession::create([
            'class_id' => $this->class->id, 'branch_id' => $branch->id, 'date' => '2026-10-07',
            'start_time' => '09:00', 'end_time' => '11:00', 'teacher_id' => $this->teacher->id, 'status' => 'scheduled',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function openStage(): void
    {
        $curriculum = SyllabusCurriculum::create(['code' => 'CUR-SL', 'title' => 'Kids SL', 'version' => 'v1', 'stage_name' => 'Chặng 1']);
        SyllabusAssignment::create([
            'user_id' => $this->teacher->id, 'curriculum_id' => $curriculum->id, 'stage_id' => $curriculum->stages()->firstOrFail()->id,
            'class_id' => $this->class->id, 'assigned_chapters' => 'x', 'status' => SyllabusAssignment::STATUS_OPEN,
        ]);
    }

    private function expectedUrl(): string
    {
        return route('syllabus.teacher-view', ['class' => $this->class->id, 'tab' => 'lessons']);
    }

    public function test_home_shift_links_to_class_syllabus_when_stage_is_open(): void
    {
        $this->openStage();

        $this->actingAs($this->teacher)->get(route('teacher.home'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shifts.0.session_id', $this->session->id)
                ->where('shifts.0.syllabus_url', $this->expectedUrl()));

        $this->actingAs($this->teacher)->get($this->expectedUrl())->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('classId', $this->class->id)
                ->where('initialTab', 'lessons'));
    }

    public function test_home_shift_has_no_syllabus_link_without_open_stage(): void
    {
        $this->actingAs($this->teacher)->get(route('teacher.home'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shifts.0.session_id', $this->session->id)
                ->where('shifts.0.syllabus_url', null));
    }

    public function test_attendance_page_links_to_class_syllabus(): void
    {
        $url = route('teacher.attendance', ['classId' => $this->class->id, 'session' => $this->session->id]);

        $this->actingAs($this->teacher)->get($url)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('syllabusUrl', null));

        $this->openStage();

        $this->actingAs($this->teacher)->get($url)->assertOk()
            ->assertSee('Mở giáo trình')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('syllabusUrl', $this->expectedUrl()));
    }
}
