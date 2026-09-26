<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mọi thao tác "Xóa" của người dùng chuyển sang xóa mềm (deleted_at) — các bảng còn lại chưa có cột này.
 * Chạy lại an toàn (bỏ qua bảng đã có cột).
 */
return new class extends Migration
{
    private const TABLES = [
        'operating_expenses',
        'courses',
        'course_levels',
        'homeworks',
        'commission_tiers',
        'surveys',
        'kpi_criteria',
        'placement_tests',
        'bank_accounts',
        'syllabus_documents',
        'syllabus_curriculums',
        'syllabus_stages',
        'syllabus_units',
        'syllabus_lessons',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, fn (Blueprint $t) => $t->softDeletes());
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropSoftDeletes());
            }
        }
    }
};
