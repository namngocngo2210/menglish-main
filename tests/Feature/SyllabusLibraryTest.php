<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusLesson;
use App\Models\SyllabusUnit;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kho syllabus (docs/kho-syllabus.md, giai đoạn 1): trường theo form Sheet 1, quyền syllabus.library,
 * danh sách giáo trình → trang chi tiết.
 */
class SyllabusLibraryTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $lead;

    private SyllabusCurriculum $curriculum;

    private SyllabusUnit $unit;

    private SyllabusLesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Cơ sở Kho', 'code' => 'KHO', 'is_active' => true]);
        $this->lead = $this->userWithRole(Roles::ACADEMIC_LEAD);
        $this->curriculum = SyllabusCurriculum::create(['code' => 'CUR-KHO', 'title' => 'Global Success 6', 'version' => 'v1']);
        $this->unit = SyllabusUnit::create(['curriculum_id' => $this->curriculum->id, 'stage_id' => $this->curriculum->stages()->firstOrFail()->id, 'unit_number' => 1, 'title' => 'My New School']);
        $this->lesson = SyllabusLesson::create(['unit_id' => $this->unit->id, 'session_no' => 1, 'title' => 'Getting started']);
    }

    public function test_lesson_saves_sheet1_fields(): void
    {
        $this->actingAs($this->lead)->post(route('syllabus.lessons.store'), [
            'unit_id' => $this->unit->id, 'session_no' => 2, 'title' => 'Mini test Unit 1',
            'lesson_type' => 'mini_test', 'procedure' => 'Warm-up', 'activity_1' => 'Slap the board', 'activity_2' => 'Role-play',
            'homework_guide' => 'Workbook p4', 'homework_quizizz' => 'Vocab U1', 'homework_video' => 'Video 30s',
            'ppt_url' => 'https://example.com/ppt', 'quizizz_url' => 'https://quizizz.com/x', 'output_product' => '10 từ',
            'notice_milestone' => 'order_print', 'note' => 'In trước 3 buổi',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('syllabus_lessons', [
            'session_no' => 2, 'lesson_type' => 'mini_test', 'notice_milestone' => 'order_print',
            'quizizz_url' => 'https://quizizz.com/x', 'activity_2' => 'Role-play', 'homework_video' => 'Video 30s',
        ]);
    }

    public function test_lesson_without_new_fields_defaults_to_normal_lesson(): void
    {
        $this->actingAs($this->lead)->post(route('syllabus.lessons.store'), ['unit_id' => $this->unit->id, 'session_no' => 3, 'title' => 'Buổi 3'])
            ->assertSessionHasNoErrors();

        $this->assertSame('lesson', SyllabusLesson::where('session_no', 3)->value('lesson_type'));
    }

    public function test_lesson_rejects_unknown_type_milestone_and_bad_urls(): void
    {
        $this->actingAs($this->lead)->post(route('syllabus.lessons.store'), [
            'unit_id' => $this->unit->id, 'session_no' => 4, 'title' => 'X',
            'lesson_type' => 'exam', 'notice_milestone' => 'order_food', 'ppt_url' => 'not a url',
        ])->assertSessionHasErrors(['lesson_type', 'notice_milestone', 'ppt_url']);
    }

    public function test_stage_saves_notice_before_sessions(): void
    {
        $stage = $this->curriculum->stages()->firstOrFail();

        $this->actingAs($this->lead)->put(route('syllabus.stages.update', $stage->id), ['name' => 'Chặng 1', 'notice_before_sessions' => 4])
            ->assertSessionHasNoErrors();
        $this->assertSame(4, $stage->fresh()->notice_before_sessions);

        $this->actingAs($this->lead)->put(route('syllabus.stages.update', $stage->id), ['name' => 'Chặng 1', 'notice_before_sessions' => 0])
            ->assertSessionHasErrors('notice_before_sessions');
    }

    public function test_lesson_editor_shows_sheet1_fields(): void
    {
        $this->actingAs($this->lead)->get(route('syllabus.builder', ['curriculum' => $this->curriculum->id, 'edit_lesson' => $this->lesson->id]))
            ->assertOk()
            ->assertSee('Loại buổi')->assertSee('Hoạt động gợi ý 2')->assertSee('Bài tập Quizizz')
            ->assertSee('Link PPT')->assertSee('Mốc thông báo')->assertSee('Order in ấn');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $user->assignRole($role);

        return $user;
    }
}
