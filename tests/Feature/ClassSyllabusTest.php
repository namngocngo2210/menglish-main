<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\ClassLessonOverride;
use App\Models\ClassModel;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusLesson;
use App\Services\Syllabus\ClassSyllabus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** Syllabus của lớp = bản gốc + bản đè theo buổi (docs/kho-syllabus.md §7.3 bước 5–7; Sheet 4 câu 3, 8). */
class ClassSyllabusTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private SyllabusAssignment $assignment;

    private SyllabusLesson $lesson3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
        $this->assignment = $this->openStage($this->stage1, ['confirmed_at' => now()]);
        $this->lesson3 = SyllabusLesson::where('curriculum_id', $this->curriculum->id)->where('session_no', 3)->firstOrFail();
        $this->lesson3->update(['content' => 'Nội dung gốc', 'ppt_url' => 'https://example.com/goc']);
    }

    public function test_override_changes_only_that_class(): void
    {
        $other = ClassModel::create(['code' => 'QT-02', 'name' => 'Lớp QT 02', 'branch_id' => $this->branch->id, 'status' => 'active', 'teacher_id' => $this->teacher->id]);
        $otherAssignment = SyllabusAssignment::create(['class_id' => $other->id, 'curriculum_id' => $this->curriculum->id, 'stage_id' => $this->stage1->id,
            'stage_name' => $this->stage1->label, 'assigned_chapters' => $this->stage1->label, 'user_id' => $this->teacher->id, 'status' => SyllabusAssignment::STATUS_OPEN, 'opened_at' => now()]);

        $this->actingAs($this->lead)->put(route('classes.syllabus.override', [$this->class->id, $this->lesson3->id]), [
            'title' => 'Bài 3', 'content' => 'Nội dung riêng lớp 01', 'reason' => 'Lớp yếu phần nghe',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $mine = app(ClassSyllabus::class)->lessons($this->assignment)->firstWhere('lesson.id', $this->lesson3->id);
        $theirs = app(ClassSyllabus::class)->lessons($otherAssignment)->firstWhere('lesson.id', $this->lesson3->id);
        $this->assertSame('Nội dung riêng lớp 01', $mine['data']['content']);
        $this->assertTrue($mine['overridden']);
        $this->assertSame('Lớp yếu phần nghe', $mine['override_reason']);
        $this->assertSame('https://example.com/goc', $mine['data']['ppt_url'], 'Trường không sửa vẫn theo bản gốc.');
        $this->assertSame('Nội dung gốc', $theirs['data']['content']);
        $this->assertFalse($theirs['overridden']);
        $this->assertSame([1, 2, 3, 4, 5, 6], app(ClassSyllabus::class)->lessons($this->assignment)->pluck('lesson.session_no')->all());
    }

    public function test_original_edit_reaches_classes_without_override_for_that_field(): void
    {
        $this->actingAs($this->lead)->put(route('classes.syllabus.override', [$this->class->id, $this->lesson3->id]), [
            'title' => 'Bài 3', 'content' => 'Nội dung riêng', 'reason' => 'Lý do',
        ])->assertSessionHasNoErrors();

        $this->lesson3->update(['content' => 'Gốc mới', 'ppt_url' => 'https://example.com/goc-moi']);

        $data = app(ClassSyllabus::class)->lessons($this->assignment->fresh())->firstWhere('lesson.id', $this->lesson3->id)['data'];
        $this->assertSame('Nội dung riêng', $data['content']);
        $this->assertSame('https://example.com/goc-moi', $data['ppt_url']);
    }

    public function test_override_requires_reason_and_notifies_teachers(): void
    {
        $url = route('classes.syllabus.override', [$this->class->id, $this->lesson3->id]);
        $this->actingAs($this->lead)->put($url, ['title' => 'Bài 3', 'content' => 'x'])->assertSessionHasErrors('reason');
        $this->actingAs($this->lead)->put($url, ['title' => 'Bài 3', 'content' => 'x', 'reason' => 'y', 'ppt_url' => 'không phải link'])->assertSessionHasErrors('ppt_url');
        $this->assertSame(0, ClassLessonOverride::count());

        $this->actingAs($this->lead)->put($url, ['title' => 'Bài 3', 'content' => 'x', 'reason' => 'y'])->assertSessionHasNoErrors();

        foreach ([$this->teacher, $this->foreignTeacher] as $user) {
            $notification = AdminNotification::where('user_id', $user->id)->sole();
            $this->assertSame('Syllabus lớp Lớp QT 01 đã được cập nhật (buổi 3).', $notification->title);
            $this->assertSame('ĐÃ XEM', $notification->action['label']);
        }
    }

    public function test_override_title_required_and_long_original_text_does_not_block_saving(): void
    {
        $long = str_repeat('Hoạt động rất dài. ', 800);
        $this->lesson3->update(['activity_1' => $long]);
        $url = route('classes.syllabus.override', [$this->class->id, $this->lesson3->id]);

        $this->actingAs($this->lead)->put($url, ['title' => '', 'reason' => 'y'])->assertSessionHasErrors('title');
        $this->actingAs($this->lead)->put($url, ['title' => 'Bài 3', 'activity_1' => $long, 'content' => 'Mới', 'reason' => 'y'])->assertSessionHasNoErrors();

        $this->assertSame(['content' => 'Mới'], ClassLessonOverride::sole()->data);
    }

    public function test_remove_override_restores_original(): void
    {
        $this->actingAs($this->lead)->put(route('classes.syllabus.override', [$this->class->id, $this->lesson3->id]), ['title' => 'Bài 3', 'content' => 'x', 'reason' => 'y']);

        $this->actingAs($this->lead)->delete(route('classes.syllabus.override.destroy', [$this->class->id, $this->lesson3->id]))->assertRedirect();

        $this->assertSame(0, ClassLessonOverride::count());
        $this->assertSame('Nội dung gốc', app(ClassSyllabus::class)->lessons($this->assignment)->firstWhere('lesson.id', $this->lesson3->id)['data']['content']);
        $this->assertSame(2, AdminNotification::where('user_id', $this->teacher->id)->count());
    }

    public function test_staff_and_teacher_can_view_tab_but_not_edit(): void
    {
        $this->actingAs($this->lead)->put(route('classes.syllabus.override', [$this->class->id, $this->lesson3->id]), ['title' => 'Bài 3', 'content' => 'Riêng', 'reason' => 'Lý do riêng']);

        foreach ([$this->staff, $this->teacher] as $user) {
            $this->actingAs($user)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'syllabus']))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('syllabus.stage_label', 'Chặng 1: Làm quen')
                    ->where('syllabus.confirmed', true)
                    ->has('syllabus.lessons', 6)
                    ->where('syllabus.lessons.2.overridden', true)
                    ->where('syllabus.lessons.2.override_reason', 'Lý do riêng')
                    // GV chỉ xem bài giảng buổi hôm nay (quyết định 7): tab lớp không trả nội dung buổi cho GV.
                    ->where('syllabus.lessons.2.data', $user->is($this->teacher) ? null : fn ($data) => $data['content'] === 'Riêng')
                    ->where('syllabus.can_edit', false)
                    ->etc());
            $this->actingAs($user)->put(route('classes.syllabus.override', [$this->class->id, $this->lesson3->id]), ['title' => 'Bài 3', 'content' => 'z', 'reason' => 'z'])->assertForbidden();
        }
        $this->actingAs($this->lead)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'syllabus']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('syllabus.can_edit', true)->etc());
    }

    public function test_class_without_open_stage_shows_empty_tab(): void
    {
        $this->assignment->update(['status' => SyllabusAssignment::STATUS_CLOSED, 'closed_at' => now()]);

        $this->actingAs($this->lead)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'syllabus']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('syllabus.stage_label', null)->where('syllabus.lessons', [])->etc());
        $this->actingAs($this->lead)->put(route('classes.syllabus.override', [$this->class->id, $this->lesson3->id]), ['title' => 'Bài 3', 'content' => 'x', 'reason' => 'y'])
            ->assertSessionHasErrors('lesson');
    }

    public function test_editing_original_lesson_notifies_teachers_of_open_classes(): void
    {
        $this->actingAs($this->lead)->put(route('syllabus.lessons.update', $this->lesson3->id), [
            'session_no' => 3, 'title' => 'Bài 3 mới', 'content' => 'Gốc sửa',
        ])->assertSessionHasNoErrors();

        $notification = AdminNotification::where('user_id', $this->teacher->id)->sole();
        $this->assertSame('Syllabus lớp Lớp QT 01 – Chặng 1: Làm quen (giáo trình Family and Friends) đã được Học thuật cập nhật.', $notification->title);
    }

    public function test_curriculum_show_lists_stage_session_count_and_alert_session(): void
    {
        $this->stage1->update(['notice_before_sessions' => 2]);

        $this->actingAs($this->lead)->get(route('syllabus.curriculums.show', $this->curriculum->id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stages.0.session_count', 6)
                ->where('stages.0.alert_at', 4)
                ->where('stages.1.session_count', 4)
                ->where('stages.1.alert_at', 1)
                ->etc());
    }
}
