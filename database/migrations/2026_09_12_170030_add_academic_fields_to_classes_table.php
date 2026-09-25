<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->string('program', 100)->nullable()->after('course_id');
            $table->string('level', 100)->nullable()->after('program');
            $table->string('room', 100)->nullable()->after('assistant_id');
            $table->foreignId('foreign_teacher_id')->nullable()->after('room')->constrained('users')->nullOnDelete();
            $table->decimal('tuition_fee', 15, 2)->nullable()->after('max_capacity');
            $table->text('notes')->nullable()->after('tuition_fee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropForeign(['foreign_teacher_id']);
            $table->dropColumn(['program', 'level', 'room', 'foreign_teacher_id', 'tuition_fee', 'notes']);
        });
    }
};
