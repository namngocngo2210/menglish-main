<?php

namespace Tests\Feature;

use App\Models\CourseLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** TK-2026-0135 — kéo thả thứ tự trình độ phải được lưu, giữ sau reload, có kiểm tra quyền, không ép thứ tự. */
class CourseLevelReorderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        foreach (['A1', 'A2', 'B1'] as $i => $code) {
            $this->ids[$code] = CourseLevel::create(['code' => $code, 'name' => "Level $code", 'target' => 'CEFR', 'is_active' => true, 'sort_order' => $i + 1])->id;
        }
    }

    private function order(): array
    {
        return CourseLevel::ordered()->pluck('code')->all();
    }

    public function test_reorder_persists_and_survives_reload_with_no_forced_order(): void
    {
        // Thứ tự tuỳ ý, không ràng buộc theo CEFR (EP5-TD-03).
        $this->actingAs($this->admin)->postJson(route('course-levels.reorder'), ['ids' => [$this->ids['B1'], $this->ids['A2'], $this->ids['A1']]])
            ->assertOk()->assertJson(['message' => 'Đã lưu thứ tự trình độ.']);

        $this->assertSame(['B1', 'A2', 'A1'], $this->order());
        $this->actingAs($this->admin)->get(route('course-levels.index'))->assertOk()
            ->assertSeeInOrder(['B1', 'A2', 'A1']);
    }

    public function test_reorder_requires_level_update_permission(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('course-levels.reorder'), ['ids' => [$this->ids['B1'], $this->ids['A1']]])->assertForbidden();
        $this->assertSame(['A1', 'A2', 'B1'], $this->order());
    }

    public function test_reorder_rejects_unknown_ids(): void
    {
        $this->actingAs($this->admin)->postJson(route('course-levels.reorder'), ['ids' => [999999]])->assertStatus(422);
    }

    public function test_page_saves_order_on_dragend_with_success_toast_and_rollback(): void
    {
        $vue = file_get_contents(resource_path('js/Pages/CourseLevels/Index.vue'));

        $this->assertStringContainsString("route('course-levels.reorder')", $vue);
        $this->assertStringContainsString('@dragend="end()"', $vue);
        $this->assertMatchesRegularExpression('/function end\(\)\s*\{.*?save\(before\)/s', $vue);
        $this->assertStringContainsString("'success'", $vue);
        $this->assertStringContainsString('rows.value = before', $vue);
    }
}
