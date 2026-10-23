<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nút ORDER theo mốc syllabus (docs/kho-syllabus.md §9.3): order in ấn / đề gắn buổi có mốc của chặng lớp đang học để biết mốc
 * nào đã order; mốc đã báo GV (1 lần / mốc).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['material_orders', 'big_test_orders'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('syllabus_assignment_id')->nullable()->constrained('syllabus_assignments')->nullOnDelete();
                $table->foreignId('syllabus_lesson_id')->nullable()->constrained('syllabus_lessons')->nullOnDelete();
            });
        }

        Schema::create('syllabus_milestone_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('syllabus_assignment_id')->constrained('syllabus_assignments')->cascadeOnDelete();
            $table->foreignId('syllabus_lesson_id')->constrained('syllabus_lessons')->cascadeOnDelete();
            $table->string('kind', 30);
            $table->timestamp('notified_at');
            $table->timestamps();
            $table->unique(['syllabus_assignment_id', 'syllabus_lesson_id', 'kind'], 'sml_notice_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('syllabus_milestone_notices');
        foreach (['material_orders', 'big_test_orders'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('syllabus_lesson_id');
                $table->dropConstrainedForeignId('syllabus_assignment_id');
            });
        }
    }
};
