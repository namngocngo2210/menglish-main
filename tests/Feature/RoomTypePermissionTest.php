<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoomTypePermissionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $academic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeLevelWithSyllabus();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $branch = Branch::create(['name' => 'Room types branch', 'code' => 'RT-PERM', 'is_active' => true]);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->academic = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $this->academic->assignRole('academic_staff');
    }

    public function test_academic_staff_can_create_and_update_but_cannot_toggle_or_delete_types(): void
    {
        $this->actingAs($this->academic)->post(route('rooms.types.store'), ['name' => 'Academic type'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $type = RoomType::where('name', 'Academic type')->sole();

        $this->put(route('rooms.types.update', $type), ['name' => 'Renamed academic type'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('Renamed academic type', $type->fresh()->name);

        $this->post(route('rooms.types.toggle', $type), ['is_active' => false])->assertForbidden();
        $this->assertTrue($type->fresh()->is_active);
        $this->delete(route('rooms.types.destroy', $type))->assertForbidden();
        $this->assertNotSoftDeleted($type);
    }

    public function test_admin_can_create_update_toggle_and_delete_types(): void
    {
        $this->actingAs($this->admin)->post(route('rooms.types.store'), ['name' => 'Admin type'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $type = RoomType::where('name', 'Admin type')->sole();

        $this->put(route('rooms.types.update', $type), ['name' => 'Renamed admin type'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('Renamed admin type', $type->fresh()->name);
        $this->post(route('rooms.types.toggle', $type), ['is_active' => false])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertFalse($type->fresh()->is_active);
        $this->delete(route('rooms.types.destroy', $type))->assertSessionHas('success');
        $this->assertSoftDeleted($type);
    }

    public function test_toggle_is_idempotent_and_logs_each_state_change_once(): void
    {
        $type = RoomType::create(['name' => 'Idempotent type', 'is_active' => true]);
        $this->actingAs($this->admin);

        foreach ([false, true] as $target) {
            for ($request = 0; $request < 2; $request++) {
                $this->post(route('rooms.types.toggle', $type), ['is_active' => $target])
                    ->assertSessionHasNoErrors()->assertSessionHas('success');
                $this->assertSame($target, $type->fresh()->is_active);
            }

            $updates = Activity::query()->where('subject_type', $type->getMorphClass())
                ->where('subject_id', $type->id)->where('event', 'updated')->get();
            $this->assertCount($target ? 2 : 1, $updates);
            $this->assertCount(1, $updates->filter(fn (Activity $log) => (bool) $log->properties['attributes']['is_active'] === $target));
        }
    }

    public function test_toggle_requires_a_boolean_target_state(): void
    {
        $type = RoomType::create(['name' => 'Validated type', 'is_active' => true]);
        $this->actingAs($this->admin)->post(route('rooms.types.toggle', $type))->assertSessionHasErrors('is_active');
        $this->post(route('rooms.types.toggle', $type), ['is_active' => 'invalid'])->assertSessionHasErrors('is_active');
        $this->assertTrue($type->fresh()->is_active);
        $this->assertSame(0, Activity::query()->where('subject_type', $type->getMorphClass())
            ->where('subject_id', $type->id)->where('event', 'updated')->count());
    }

    public function test_room_pages_expose_edit_and_manage_permissions(): void
    {
        foreach ([[$this->academic, false], [$this->admin, true]] as [$user, $canManage]) {
            foreach (['rooms.types.index' => 'Rooms/Types', 'rooms.create' => 'Rooms/Form'] as $route => $component) {
                $this->actingAs($user)->get(route($route))->assertOk()
                    ->assertInertia(fn (AssertableInertia $page) => $page->component($component)
                        ->where('canEditTypes', true)->where('canManageTypes', $canManage));
            }
        }
    }

    public function test_default_grants_exclude_manager_and_admin_gate_remains_unrestricted(): void
    {
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('room.edit_types'));
        $this->assertTrue(Role::findByName('academic_staff')->hasPermissionTo('room.edit_types'));
        $this->assertFalse(Role::findByName('manager')->hasPermissionTo('room.edit_types'));
        Role::findByName('admin')->revokePermissionTo(['room.edit_types', 'room.manage_types']);
        $this->assertTrue($this->admin->can('room.edit_types'));
        $this->assertTrue($this->admin->can('room.manage_types'));
    }

    public function test_migration_preserves_custom_type_managers_and_is_reversible_and_repeatable(): void
    {
        $custom = Role::create(['name' => 'custom_type_admin', 'guard_name' => 'web']);
        $custom->givePermissionTo('room.manage_types');
        Permission::findByName('room.edit_types')->delete();
        $migration = require database_path('migrations/2026_12_17_090000_grant_room_edit_types_permission.php');

        $migration->up();
        $migration->up();
        foreach (['admin', 'academic_staff', $custom->name] as $name) {
            $this->assertTrue(Role::findByName($name)->hasPermissionTo('room.edit_types'));
        }
        $this->assertFalse(Role::findByName('manager')->hasPermissionTo('room.edit_types'));
        $this->assertSame(1, $custom->permissions()->where('name', 'room.edit_types')->count());

        $migration->down();
        $migration->down();
        $this->assertFalse(Permission::where('name', 'room.edit_types')->where('guard_name', 'web')->exists());
        $this->assertTrue($custom->fresh()->hasPermissionTo('room.manage_types'));
    }
}
