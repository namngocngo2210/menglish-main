<?php

namespace Tests\Feature;

use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Support\Navigation\SidebarMenu;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** Luồng màn hình GV: lớp của tôi → chặng (XÁC NHẬN) → bài giảng → báo cáo (docs/luong-giao-vien.md). */
class TeacherClassFlowTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private ClassModel $otherClass;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
        $this->otherClass = ClassModel::create(['code' => 'QT-02', 'name' => 'Lớp QT 02', 'branch_id' => $this->branch->id, 'status' => 'active']);
    }

    public function test_teacher_portal_menu_follows_the_teaching_flow(): void
    {
        $groups = collect(app(SidebarMenu::class)->groupsFor($this->teacher));

        // "Lớp của tôi" nằm ở nhóm Lớp học (khu Đào tạo), cạnh Giáo trình / E-learning / Big Test.
        $classes = $groups->firstWhere('id', 'classes');
        $this->assertSame('Đào tạo', $classes['section']);
        $this->assertSame(['Lớp của tôi'], collect($classes['items'])->pluck('label')->all());
        $this->assertSame(route('classes.index', ['mine' => 1]), $classes['url']);

        $portal = $groups->firstWhere('id', 'teacher_portal');
        $labels = collect($portal['items'])->pluck('label')->all();
        $this->assertSame(['Bài giảng hôm nay', 'Syllabus & tài liệu', 'Check-in & Điểm danh hôm nay'], array_slice($labels, 0, 3));
        $this->assertNotContains('Lớp của tôi', $labels);
    }

    public function test_class_manager_menu_still_opens_the_full_class_list(): void
    {
        $classes = collect(app(SidebarMenu::class)->groupsFor($this->manager))->firstWhere('id', 'classes');

        $this->assertSame('Danh sách lớp', $classes['items'][0]['label']);
        $this->assertSame(route('classes.index'), $classes['url']);
    }

    public function test_teacher_support_sessions_keep_the_teacher_portal_tabs(): void
    {
        $this->actingAs($this->teacher)->get(route('tasks.support-sessions', ['mine' => 1]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shell.workspace.tabs', fn ($tabs) => collect($tabs)->contains(fn ($t) => $t['label'] === 'Buổi bổ trợ của tôi' && $t['active']))
                ->etc());
    }

    public function test_my_classes_lists_only_classes_the_viewer_teaches(): void
    {
        $this->actingAs($this->teacher)->get(route('classes.index', ['mine' => 1]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('classes.data', fn ($rows) => collect($rows)->pluck('code')->all() === ['QT-01'])
                ->where('filters.mine', 1)
                // Trên Danh sách lớp, GV thấy tab "Lớp của tôi" của nhóm Lớp học.
                ->where('shell.workspace.tabs', fn ($tabs) => collect($tabs)->contains(fn ($t) => $t['label'] === 'Lớp của tôi' && $t['active']))
                ->etc());

        // Người quản lý thấy mọi lớp, "Lớp của tôi" chỉ còn lớp mình dạy.
        $this->actingAs($this->manager)->get(route('classes.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('classes.data', fn ($rows) => count($rows) === 2)->etc());
        $this->otherClass->update(['teacher_id' => $this->manager->id]);
        $this->actingAs($this->manager)->get(route('classes.index', ['mine' => 1]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('classes.data', fn ($rows) => collect($rows)->pluck('code')->all() === ['QT-02'])->etc());
    }

    public function test_teaching_stage_card_links_confirm_lesson_and_class_page(): void
    {
        $assignment = $this->openStage($this->stage1, ['start_date' => today()->addDay(), 'assigned_at' => now()]);
        $this->classSession(1, '18:00');

        $this->actingAs($this->teacher)->get(route('syllabus.teaching-stages'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('assignments.0.confirm_url', route('syllabus.assignments.read', $assignment->id))
                ->where('assignments.0.confirm_due', today()->addDay()->setTime(17, 0)->format('H:i d/m/Y'))
                ->where('assignments.0.lesson_url', route('teacher.lesson-today', ['class' => $this->class->id]))
                ->where('assignments.0.class_url', route('classes.show', $this->class->id))
                ->etc());

        $assignment->update(['confirmed_at' => now(), 'confirmed_by' => $this->teacher->id]);
        $this->actingAs($this->teacher)->get(route('syllabus.teaching-stages'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('assignments.0.confirm_url', null)->etc());

        // Trợ giảng không xác nhận được chặng.
        $assistant = $this->userWithRole(Roles::ASSISTANT, 'Trợ giảng');
        $this->class->update(['assistant_id' => $assistant->id]);
        $assignment->update(['confirmed_at' => null]);
        $this->actingAs($assistant)->get(route('syllabus.teaching-stages'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('assignments.0.confirm_url', null)->etc());
    }

    public function test_general_report_classes_open_the_class_page(): void
    {
        $this->actingAs($this->teacher)->get(route('teacher.general-report'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('myClasses.0.url', route('classes.show', $this->class->id))->etc());
    }

    public function test_marking_taught_requires_confirmed_stage(): void
    {
        $assignment = $this->openStage($this->stage1, ['start_date' => today()->subDays(7), 'opened_at' => today()->subDays(7)]);
        $today = $this->classSession(0, '18:00');

        $this->actingAs($this->teacher)->get(route('teacher.lesson-today', ['class' => $this->class->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can_mark', false)
                ->where('confirm_url', route('syllabus.assignments.read', $assignment->id))
                ->etc());
        $this->actingAs($this->teacher)->post(route('teacher.lesson-today.taught', $this->class->id), ['class_session_id' => $today->id])
            ->assertSessionHasErrors('class_session_id');
        $this->assertFalse($assignment->progress()->exists());

        $this->readAndConfirm($this->teacher, $assignment)->assertSessionHasNoErrors();
        $this->actingAs($this->teacher)->get(route('teacher.lesson-today', ['class' => $this->class->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can_mark', true)->where('confirm_url', null)->etc());
        $this->actingAs($this->teacher)->post(route('teacher.lesson-today.taught', $this->class->id), ['class_session_id' => $today->id])
            ->assertSessionHasNoErrors();
    }

    private function classSession(int $days, string $start): ClassSession
    {
        return ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->addDays($days)->toDateString(),
            'start_time' => $start, 'end_time' => '19:30', 'type' => ClassSession::TYPE_REGULAR, 'status' => 'scheduled', 'teacher_id' => $this->teacher->id]);
    }
}
