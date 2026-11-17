<?php

use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TK-2026-0134: vai trò ở form nhân sự lấy từ danh mục Chức vụ. system_categories.role_code ánh xạ chức vụ -> vai trò
 * (phân quyền vẫn đi qua vai trò); users.position_id lưu chức vụ hiển thị. Chức vụ không ánh xạ không cấp quyền nào.
 */
return new class extends Migration
{
    private const DEFAULT_MAP = [
        'POS_01' => Roles::ADMIN,
        'POS_02' => Roles::MANAGER,
        'POS_03' => Roles::SALES_CONSULTANT,
        'POS_04' => Roles::ACADEMIC_STAFF,
        'POS_05' => Roles::ACCOUNTANT,
        'POS_06' => Roles::TEACHER_FULLTIME,
        'POS_07' => Roles::FOREIGN_TEACHER,
        'POS_08' => Roles::ASSISTANT,
    ];

    public function up(): void
    {
        Schema::table('system_categories', function (Blueprint $table) {
            $table->string('role_code', 100)->nullable()->after('name');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('position_id')->nullable()->after('branch_id')->constrained('system_categories')->nullOnDelete();
        });

        // Ánh xạ các chức vụ đã seed sẵn (theo mã POS_xx) sang vai trò hiện có.
        foreach (self::DEFAULT_MAP as $code => $role) {
            DB::table('system_categories')->where('type', 'position')->where('code', $code)->whereNull('role_code')->update(['role_code' => $role]);
        }
        // POS_09/POS_10 (Trưởng học thuật, GV bán thời gian) do SystemCategorySeeder thêm (migration không chèn dòng dữ liệu).
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('position_id');
        });
        Schema::table('system_categories', function (Blueprint $table) {
            $table->dropColumn('role_code');
        });
    }
};
