<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CoverMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_unique_registration_index_and_reversible_additive_migration(): void
    {
        $this->assertTrue(Schema::hasTable('cover_requests'));
        $this->assertTrue(Schema::hasTable('cover_registrations'));
        $this->assertTrue(Schema::hasIndex('cover_registrations', ['cover_request_id', 'teacher_id'], 'unique'));
        $legacyColumns = Schema::getColumnListing('substitute_sessions');
        $teacher = User::factory()->create();
        $legacyId = DB::table('substitute_sessions')->insertGetId([
            'substitute_teacher_id' => $teacher->id, 'session_date' => '2026-12-11', 'reason' => 'Legacy cover',
        ]);
        $migration = require database_path('migrations/2026_12_08_090000_create_cover_requests_tables.php');
        $migration->down();
        try {
            $this->assertFalse(Schema::hasTable('cover_requests'));
            $this->assertFalse(Schema::hasTable('cover_registrations'));
            $this->assertTrue(Schema::hasTable('substitute_sessions'));
            $this->assertSame($legacyColumns, Schema::getColumnListing('substitute_sessions'));
            $this->assertDatabaseHas('substitute_sessions', ['id' => $legacyId, 'reason' => 'Legacy cover']);
        } finally {
            $migration->up();
        }
        $this->assertTrue(Schema::hasTable('cover_requests'));
        $this->assertTrue(Schema::hasIndex('cover_registrations', ['cover_request_id', 'teacher_id'], 'unique'));
    }

    public function test_permission_migration_grants_existing_roles_and_can_be_reapplied(): void
    {
        $migration = require database_path('migrations/2026_12_08_090100_grant_cover_permissions.php');
        $migration->down();
        $this->assertFalse(Permission::where('name', 'cover.request')->exists());
        $this->assertFalse(Permission::where('name', 'cover.approve')->exists());
        $migration->up();
        $migration->up();
        foreach ([Roles::TEACHER_FULLTIME, Roles::TEACHER_PARTTIME, Roles::FOREIGN_TEACHER] as $name) {
            $this->assertTrue(Role::findByName($name)->hasPermissionTo('cover.request'));
            $this->assertFalse(Role::findByName($name)->hasPermissionTo('cover.approve'));
        }
        $this->assertTrue(Role::findByName(Roles::ACADEMIC_STAFF)->hasPermissionTo('cover.approve'));
        foreach ([Roles::ASSISTANT, Roles::STUDENT] as $name) {
            $this->assertFalse(Role::findByName($name)->hasPermissionTo('cover.request'));
            $this->assertFalse(Role::findByName($name)->hasPermissionTo('cover.approve'));
        }
        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);
        $this->assertTrue($admin->can('cover.approve'));
    }
}
