<?php

namespace Tests\Feature;

use App\Models\CourseLevel;
use App\Models\SyllabusCurriculum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** TK-2026-0136 — EP5-TD-05 / TD R-02: một trình độ gắn tối đa 2 Syllabus. */
class CourseLevelTwoSyllabusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SyllabusCurriculum $a;

    private SyllabusCurriculum $b;

    protected function setUp(): void
    {
        parent::setUp();
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->a = SyllabusCurriculum::create(['code' => 'SYL-A', 'title' => 'A', 'version' => 'v1']);
        $this->b = SyllabusCurriculum::create(['code' => 'SYL-B', 'title' => 'B', 'version' => 'v2']);
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['name' => 'Lv', 'target' => 'T', 'lessons_count' => 10, 'is_active' => '1'];
    }

    public function test_update_saves_two_syllabi_and_index_shows_both(): void
    {
        $level = CourseLevel::create(['code' => 'L1', 'name' => 'L1', 'target' => 'T', 'is_active' => true, 'syllabus_curriculum_id' => $this->a->id]);

        $this->actingAs($this->admin)->put(route('course-levels.update', $level->id), $this->payload([
            'syllabus_curriculum_id' => $this->a->id, 'secondary_syllabus_curriculum_id' => $this->b->id,
        ]))->assertSessionHasNoErrors();

        $level->refresh();
        $this->assertSame($this->a->id, $level->syllabus_curriculum_id);
        $this->assertSame($this->b->id, $level->secondary_syllabus_curriculum_id);

        $this->actingAs($this->admin)->get(route('course-levels.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('levels.data.0.syllabi', fn ($s) => collect($s)->pluck('label')->all() === ['SYL-A.v1', 'SYL-B.v2'])
            ->where('stats.syllabus', 2));
    }

    public function test_guard_rejects_same_syllabus_twice_and_unknown_second(): void
    {
        $level = CourseLevel::create(['code' => 'L1', 'name' => 'L1', 'target' => 'T', 'is_active' => true]);

        $this->actingAs($this->admin)->put(route('course-levels.update', $level->id), $this->payload([
            'syllabus_curriculum_id' => $this->a->id, 'secondary_syllabus_curriculum_id' => $this->a->id,
        ]))->assertSessionHasErrors('secondary_syllabus_curriculum_id');

        $this->actingAs($this->admin)->put(route('course-levels.update', $level->id), $this->payload([
            'syllabus_curriculum_id' => $this->a->id, 'secondary_syllabus_curriculum_id' => 99999,
        ]))->assertSessionHasErrors('secondary_syllabus_curriculum_id');

        $this->assertNull($level->fresh()->secondary_syllabus_curriculum_id);
    }

    public function test_only_second_syllabus_is_promoted_to_primary_and_existing_single_data_is_kept(): void
    {
        $legacy = CourseLevel::create(['code' => 'OLD', 'name' => 'Old', 'target' => 'T', 'is_active' => true, 'syllabus_curriculum_id' => $this->a->id]);
        $this->assertNull($legacy->fresh()->secondary_syllabus_curriculum_id);
        $this->assertSame($this->a->id, $legacy->fresh()->syllabus_curriculum_id);

        $this->actingAs($this->admin)->put(route('course-levels.update', $legacy->id), $this->payload([
            'syllabus_curriculum_id' => '', 'secondary_syllabus_curriculum_id' => $this->b->id,
        ]))->assertSessionHasNoErrors();
        $legacy->refresh();
        $this->assertSame($this->b->id, $legacy->syllabus_curriculum_id);
        $this->assertNull($legacy->secondary_syllabus_curriculum_id);
    }

    public function test_class_placement_level_picker_lists_level_with_two_syllabi_once(): void
    {
        CourseLevel::create(['code' => 'TWO', 'name' => 'Two', 'target' => 'T', 'is_active' => true,
            'syllabus_curriculum_id' => $this->a->id, 'secondary_syllabus_curriculum_id' => $this->b->id]);

        $this->actingAs($this->admin)->get(route('classes.create'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('levels', fn ($levels) => collect($levels)->pluck('value')->all() === ['TWO']));
    }

    public function test_deleting_a_syllabus_clears_it_from_the_second_slot(): void
    {
        $level = CourseLevel::create(['code' => 'L1', 'name' => 'L1', 'target' => 'T', 'is_active' => true,
            'syllabus_curriculum_id' => $this->a->id, 'secondary_syllabus_curriculum_id' => $this->b->id]);

        $this->actingAs($this->admin)->delete(route('syllabus.curriculums.destroy', $this->b->id));

        $this->assertNull($level->fresh()->secondary_syllabus_curriculum_id);
        $this->assertSame($this->a->id, $level->fresh()->syllabus_curriculum_id);
    }
}
