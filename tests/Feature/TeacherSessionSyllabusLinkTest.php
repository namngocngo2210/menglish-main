<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusDocument;
use App\Models\SyllabusStage;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Lối tắt "Mở giáo trình" từ buổi dạy (lịch dạy + điểm danh) sang tài liệu của chặng lớp đang học
 * (màn GV xem bài giảng, scope=stage), chỉ khi lớp đang có chặng mở.
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

    private function openStage(): SyllabusStage
    {
        $curriculum = SyllabusCurriculum::create(['code' => 'CUR-SL', 'title' => 'Kids SL', 'version' => 'v1', 'stage_name' => 'Chặng 1']);
        $stage = $curriculum->stages()->firstOrFail();
        SyllabusAssignment::create([
            'user_id' => $this->teacher->id, 'curriculum_id' => $curriculum->id, 'stage_id' => $stage->id,
            'class_id' => $this->class->id, 'assigned_chapters' => 'x', 'status' => SyllabusAssignment::STATUS_OPEN,
        ]);

        return $stage;
    }

    private function document(int $curriculumId, ?int $stageId, string $title): SyllabusDocument
    {
        return SyllabusDocument::create([
            'curriculum_id' => $curriculumId, 'stage_id' => $stageId, 'title' => $title, 'file_path' => 'syllabus/'.$title.'.pdf',
            'original_name' => $title.'.pdf', 'extension' => 'pdf', 'visible_to_teachers' => true,
        ]);
    }

    private function expectedUrl(): string
    {
        return route('syllabus.teacher-view', ['class' => $this->class->id, 'scope' => 'stage']);
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

    public function test_stage_scope_lists_only_documents_of_class_open_stage(): void
    {
        $stage = $this->openStage();
        $nextStage = SyllabusStage::create(['curriculum_id' => $stage->curriculum_id, 'position' => 2, 'name' => 'Chặng 2']);
        $other = SyllabusCurriculum::create(['code' => 'CUR-X', 'title' => 'Other', 'version' => 'v1', 'stage_name' => 'Chặng 1']);
        $this->document($stage->curriculum_id, $stage->id, 'Slide chang 1');
        $this->document($stage->curriculum_id, null, 'Tai lieu chung');
        $this->document($stage->curriculum_id, $nextStage->id, 'Slide chang 2');
        $this->document($other->id, $other->stages()->firstOrFail()->id, 'Giao trinh khac');

        $this->actingAs($this->teacher)->get($this->expectedUrl())->assertOk()
            ->assertSee('Xem tất cả tài liệu')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('initialTab', 'docs')
                ->where('documents', fn ($docs) => collect($docs)->pluck('title')->sort()->values()->all() === ['Slide chang 1', 'Tai lieu chung'])
                ->where('documents.0.url', fn ($url) => str_contains($url, 'scope=stage'))
                ->where('stageScope.class_name', $this->class->name)
                ->where('stageScope.all_url', route('syllabus.teacher-view', ['class' => $this->class->id])));

        // Không có scope=stage → GV vẫn chỉ thấy tài liệu chặng đang mở của lớp mình (Kho syllabus, docs/kho-syllabus.md §3.4).
        $this->actingAs($this->teacher)->get(route('syllabus.teacher-view', ['class' => $this->class->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('documents', 2)
                ->where('stageScope', null));
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
