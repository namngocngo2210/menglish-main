<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * TK-2026-0137: phạm vi "Theo lớp" của Phân quyền cá nhân phải (1) lưu được khi chỉ đổi phạm vi và
 * (2) giới hạn thật danh sách / chi tiết / sửa / xóa lớp ở server.
 */
class ClassScopeOverrideTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $staff;

    private ClassModel $x;

    private ClassModel $y;

    private ClassModel $z;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'CN Scope', 'code' => 'SC', 'is_active' => true]);
        $this->admin = $this->makeUser('admin');
        $this->staff = $this->makeUser('academic_staff');

        $course = Course::create(['code' => 'SC-C', 'name' => 'Course SC', 'tuition_fee' => 1000000, 'total_lessons' => 10, 'is_active' => true]);
        $make = fn (string $code) => ClassModel::create([
            'code' => $code, 'name' => "Lớp {$code}", 'course_id' => $course->id, 'branch_id' => $this->branch->id,
            'max_capacity' => 10, 'tuition_fee' => 1000000, 'status' => 'active',
        ]);
        $this->x = $make('SC-X');
        $this->y = $make('SC-Y');
        $this->z = $make('SC-Z');
    }

    private function saveScope(array $ids, string $type = 'class', array $overrides = []): void
    {
        $this->actingAs($this->admin)->put(route('users.permissions.update', $this->staff), [
            'overrides' => ['class' => $overrides],
            'scope' => ['class' => ['type' => $type, 'ids' => $ids]],
        ])->assertSessionHasNoErrors();
    }

    private function listedIds(User $user): array
    {
        return ClassModel::query()->visibleTo($user->fresh())->pluck('id')->sort()->values()->all();
    }

    public function test_scope_only_change_is_persisted_and_logged(): void
    {
        $this->saveScope([$this->x->id, $this->y->id], overrides: ['view' => 'inherit', 'delete' => 'inherit']);

        $this->assertSame(
            [$this->x->id, $this->y->id],
            UserPermissionOverride::query()->where('user_id', $this->staff->id)->where('module', 'class')
                ->where('scope_type', 'class')->pluck('scope_id')->map(fn ($i) => (int) $i)->sort()->values()->all()
        );
        $this->actingAs($this->admin)->get(route('users.permissions.edit', $this->staff))->assertOk();

        $log = Activity::query()->where('log_name', 'Người dùng & Phân quyền')->latest('id')->first();
        $this->assertNotSame([], $log->properties['attributes']['overrides']);

        // Đổi về toàn hệ thống: xóa phạm vi.
        $this->saveScope([], 'all');
        $this->assertSame(0, UserPermissionOverride::query()->where('user_id', $this->staff->id)->where('scope_type', 'class')->count());
    }

    public function test_scope_without_any_matrix_row_is_still_saved(): void
    {
        $this->actingAs($this->admin)->put(route('users.permissions.update', $this->staff), [
            'scope' => ['class' => ['type' => 'class', 'ids' => [$this->x->id]]],
        ])->assertSessionHasNoErrors();

        $this->assertSame([$this->x->id], $this->listedIds($this->staff));
    }

    public function test_scope_marker_grants_no_extra_permission(): void
    {
        $role = $this->makeUser('teacher');
        $this->actingAs($this->admin)->put(route('users.permissions.update', $role), [
            'scope' => ['class' => ['type' => 'class', 'ids' => [$this->x->id]]],
        ])->assertSessionHasNoErrors();

        $role = $role->fresh();
        $this->assertFalse($role->can('class.delete'));
        $this->assertFalse($role->can('class.update'));
    }

    public function test_class_scope_restricts_list_show_edit_update_and_delete(): void
    {
        $this->assertCount(3, $this->listedIds($this->staff));
        $this->saveScope([$this->x->id, $this->y->id]);
        $this->assertSame([$this->x->id, $this->y->id], $this->listedIds($this->staff));

        $staff = $this->staff->fresh();
        $this->actingAs($staff)->get(route('classes.show', $this->x->id))->assertOk();
        $this->actingAs($staff)->get(route('classes.show', $this->z->id))->assertNotFound();
        $this->assertContains($this->actingAs($staff)->get(route('classes.edit', $this->z->id))->status(), [403, 404]);
        $this->assertContains($this->actingAs($staff)->delete(route('classes.destroy', $this->z->id))->status(), [403, 404]);
        $this->assertNotNull(ClassModel::find($this->z->id));

        $html = $this->actingAs($staff)->get(route('classes.index'))->assertOk()->getContent();
        $this->assertStringContainsString('SC-X', $html);
        $this->assertStringNotContainsString('SC-Z', $html);
    }

    public function test_scope_survives_toggling_another_permission_and_still_restricts(): void
    {
        $this->saveScope([$this->x->id], overrides: ['delete' => 'allow']);
        $this->assertSame([$this->x->id], $this->listedIds($this->staff));
    }

    public function test_branch_scope_restricts_to_branch_classes(): void
    {
        $other = Branch::create(['name' => 'CN Khác', 'code' => 'SD', 'is_active' => true]);
        $outside = ClassModel::create([
            'code' => 'SC-OUT', 'name' => 'Lớp ngoài', 'course_id' => $this->x->course_id, 'branch_id' => $other->id,
            'max_capacity' => 10, 'tuition_fee' => 1000000, 'status' => 'active',
        ]);
        $this->saveScope([$other->id], 'branch');

        $this->assertSame([$outside->id], $this->listedIds($this->staff));
    }

    public function test_user_without_class_scope_is_unchanged(): void
    {
        $this->assertSame(
            collect([$this->x, $this->y, $this->z])->pluck('id')->sort()->values()->all(),
            $this->listedIds($this->staff)
        );
        $this->assertSame(
            collect([$this->x, $this->y, $this->z])->pluck('id')->sort()->values()->all(),
            $this->listedIds($this->admin)
        );
    }

    public function test_scope_of_one_user_does_not_affect_another(): void
    {
        $other = $this->makeUser('academic_staff');
        $this->saveScope([$this->x->id]);

        $this->assertCount(3, $this->listedIds($other));
    }

    public function test_invalid_class_ids_and_empty_scope_are_rejected(): void
    {
        $this->actingAs($this->admin)->put(route('users.permissions.update', $this->staff), [
            'scope' => ['class' => ['type' => 'class', 'ids' => [999999]]],
        ])->assertSessionHasErrors('scope.class.ids');
        $this->actingAs($this->admin)->put(route('users.permissions.update', $this->staff), [
            'scope' => ['class' => ['type' => 'class', 'ids' => []]],
        ])->assertSessionHasErrors('scope.class.ids');
        $this->assertSame(0, UserPermissionOverride::query()->where('user_id', $this->staff->id)->count());
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
