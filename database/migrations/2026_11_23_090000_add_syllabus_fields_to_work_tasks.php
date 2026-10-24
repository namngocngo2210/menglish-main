<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Việc cuối chặng cho Học thuật (docs/kho-syllabus.md §9.1): việc gắn chặng của lớp (1 việc / chặng), ngày đã nhắc quá hạn
 * (nhắc mỗi ngày 1 lần).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_tasks', function (Blueprint $table) {
            $table->foreignId('syllabus_assignment_id')->nullable()->constrained('syllabus_assignments')->nullOnDelete();
            $table->date('last_reminded_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('syllabus_assignment_id');
            $table->dropColumn('last_reminded_on');
        });
    }
};
