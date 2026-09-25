<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mini_test_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // Giáo viên chấm
            $table->string('name'); // Tên bài mini test
            $table->decimal('score', 5, 2)->nullable();
            $table->decimal('max_score', 5, 2)->default(10);
            $table->date('test_date');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['class_id', 'student_id', 'name', 'test_date'], 'mini_score_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mini_test_scores');
    }
};
