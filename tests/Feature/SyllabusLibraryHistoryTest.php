<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusLesson;
use App\Models\SyllabusUnit;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Kho syllabus — tab Lịch sử của trang chi tiết giáo trình (docs/kho-syllabus.md §3.4). */
class SyllabusLibraryHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    private SyllabusCurriculum $curriculum;

    private SyllabusLesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::create(['name' => 'Cơ sở Lịch sử', 'code' => 'LS', 'is_active' => true]);
        $this->lead = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id, 'name' => 'Học Thuật Lịch Sử']);
        $this->lead->assignRole(Roles::ACADEMIC_LEAD);
        [$this->curriculum, $this->lesson] = $this->curriculumWithLesson('CUR-LS');
    }

    public function test_lesson_update_is_logged_with_curriculum_id(): void
    {
        $this->actingAs($this->lead)->put(route('syllabus.lessons.update', $this->lesson->id), ['session_no' => 1, 'title' => 'Tên mới'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->lead)->get(route('syllabus.curriculums.show', ['id' => $this->curriculum->id, 'tab' => 'history']))
            ->assertOk()
            ->assertSee('Lịch sử thay đổi')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('tab', 'history')
                ->where('history.data.0.event', 'Sửa')
                ->where('history.data.0.causer', 'Học Thuật Lịch Sử')
                ->where('history.data.0.changes', fn ($changes) => collect($changes)->contains(fn ($c) => $c['field'] === 'title' && $c['old'] === 'Buổi 1' && $c['new'] === 'Tên mới')));
    }

    public function test_history_is_scoped_to_curriculum(): void
    {
        [, $otherLesson] = $this->curriculumWithLesson('CUR-KHAC');
        $this->actingAs($this->lead)->put(route('syllabus.lessons.update', $otherLesson->id), ['session_no' => 1, 'title' => 'Sửa giáo trình khác']);

        $this->actingAs($this->lead)->get(route('syllabus.curriculums.show', ['id' => $this->curriculum->id, 'tab' => 'history']))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('history.data', fn ($rows) => collect($rows)
                ->flatMap(fn ($r) => $r['changes'])->doesntContain(fn ($c) => ($c['new'] ?? null) === 'Sửa giáo trình khác')));
    }

    public function test_history_is_only_loaded_on_its_tab(): void
    {
        $this->actingAs($this->lead)->get(route('syllabus.curriculums.show', $this->curriculum->id))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('history', null));
    }

    /** @return array{0: SyllabusCurriculum, 1: SyllabusLesson} */
    private function curriculumWithLesson(string $code): array
    {
        $curriculum = SyllabusCurriculum::create(['code' => $code, 'title' => 'Giáo trình '.$code, 'version' => 'v1']);
        $unit = SyllabusUnit::create(['curriculum_id' => $curriculum->id, 'stage_id' => $curriculum->stages()->firstOrFail()->id, 'unit_number' => 1, 'title' => 'Unit 1']);

        return [$curriculum, SyllabusLesson::create(['unit_id' => $unit->id, 'session_no' => 1, 'title' => 'Buổi 1'])];
    }
}
