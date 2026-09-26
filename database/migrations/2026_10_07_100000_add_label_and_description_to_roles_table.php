<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RBAC linh hoạt: tên hiển thị (tiếng Việt) và mô tả của vai trò do Admin đặt / đổi trên màn Vai trò.
     * Mã vai trò (roles.name) của vai trò hệ thống giữ nguyên.
     */
    public function up(): void
    {
        $table = config('permission.table_names.roles', 'roles');

        Schema::table($table, function (Blueprint $table) {
            $table->string('label', 150)->nullable()->after('name');
            $table->string('description', 500)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        $table = config('permission.table_names.roles', 'roles');

        Schema::table($table, function (Blueprint $table) {
            $table->dropColumn(['label', 'description']);
        });
    }
};
