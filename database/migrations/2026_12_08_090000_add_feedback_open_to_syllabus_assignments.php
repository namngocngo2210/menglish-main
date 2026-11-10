<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * TK-2026-0058: cờ "mở thu thập feedback phụ huynh" theo chặng của lớp (SyllabusAssignment) + quyền bật/tắt.
 * Chặng đang học hiện tại được mở sẵn để cổng phụ huynh không bị khóa đột ngột; chặng khác mặc định đóng.
 */
return new class extends Migration
{
    private const PERMISSION = 'class_quality.parent_feedback';

    private const ROLES = ['manager', 'academic_staff'];

    public function up(): void
    {
        Schema::table('syllabus_assignments', function (Blueprint $table) {
            $table->boolean('feedback_open')->default(false);
        });
        DB::table('syllabus_assignments')->where('status', 'in_progress')->update(['feedback_open' => true]);

        Permission::findOrCreate(self::PERMISSION, 'web');
        foreach (self::ROLES as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
            if ($role && ! $role->hasPermissionTo(self::PERMISSION)) {
                $role->givePermissionTo(self::PERMISSION);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', self::PERMISSION)->where('guard_name', 'web')->delete();
        Schema::table('syllabus_assignments', function (Blueprint $table) {
            $table->dropColumn('feedback_open');
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
