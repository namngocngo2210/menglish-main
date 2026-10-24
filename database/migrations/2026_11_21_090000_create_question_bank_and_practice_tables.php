<?php

use App\Support\Practice\PracticeCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // Chênh lệch giữa khối thực tế và khối tính từ năm sinh (năm sau tự lên lớp).
            $table->smallInteger('grade_adjust')->default(0)->after('study_started_on');
        });

        Schema::create('question_bank_passages', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('grade');
            $table->string('term', 3);
            $table->string('skill');
            $table->string('type');
            $table->text('instruction')->nullable();
            $table->text('content');
            $table->string('image_path')->nullable();
            $table->string('source_school')->nullable();
            $table->string('source_year')->nullable();
            $table->string('source_file')->nullable();
            $table->string('source_key')->unique();
            $table->timestamps();

            $table->index(['grade', 'term', 'type']);
        });

        Schema::create('question_bank_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passage_id')->nullable()->constrained('question_bank_passages')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(1);
            $table->unsignedTinyInteger('grade');
            $table->string('term', 3);
            $table->string('skill');
            $table->string('type');
            $table->string('knowledge')->nullable();
            $table->text('instruction')->nullable();
            $table->text('stem');
            $table->json('options');
            $table->char('answer', 1); // A|B|C|D, kiểm tra ở tầng ứng dụng (tương thích SQLite)
            $table->string('image_path')->nullable();
            $table->string('status', 10)->default('draft'); // draft | approved | hidden
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->string('source_school')->nullable();
            $table->string('source_year')->nullable();
            $table->string('source_file')->nullable();
            $table->string('source_key')->unique();
            $table->timestamps();

            $table->index(['grade', 'term', 'status', 'type']);
        });

        Schema::create('practice_blueprints', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('grade');
            $table->string('term', 3);
            $table->unsignedSmallInteger('duration_minutes');
            $table->json('items'); // [{skill, type, count}]
            $table->timestamps();

            $table->unique(['grade', 'term']);
        });

        Schema::create('practice_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('grade');
            $table->string('term', 3);
            $table->string('status', 12)->default('in_progress'); // in_progress | submitted
            $table->timestamp('started_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->boolean('auto_submitted')->default(false);
            $table->string('submit_reason', 12)->nullable(); // timeout | violations
            $table->unsignedSmallInteger('total')->default(0);
            $table->unsignedSmallInteger('correct')->default(0);
            $table->decimal('score', 4, 2)->nullable();
            $table->json('skill_scores')->nullable();
            $table->unsignedSmallInteger('violation_count')->default(0);
            $table->json('violation_log')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'created_at']);
            $table->index(['grade', 'term', 'submitted_at']);
            $table->index(['status', 'deadline_at']);
        });

        Schema::create('practice_attempt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('practice_attempts')->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained('question_bank_questions')->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('skill');
            $table->string('type');
            $table->json('snapshot'); // instruction, passage, image, stem, options, answer
            $table->char('chosen', 1)->nullable();
            $table->boolean('is_correct')->nullable();
            $table->timestamps();

            $table->unique(['attempt_id', 'position']);
            $table->index(['question_id', 'is_correct']);
        });

        $now = now();
        $rows = [];
        foreach (PracticeCatalog::GRADES as $grade) {
            foreach (array_keys(PracticeCatalog::TERMS) as $term) {
                $rows[] = [
                    'grade' => $grade,
                    'term' => $term,
                    'duration_minutes' => PracticeCatalog::DEFAULT_DURATION,
                    'items' => json_encode(PracticeCatalog::DEFAULT_ITEMS, JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('practice_blueprints')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_attempt_items');
        Schema::dropIfExists('practice_attempts');
        Schema::dropIfExists('practice_blueprints');
        Schema::dropIfExists('question_bank_questions');
        Schema::dropIfExists('question_bank_passages');

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('grade_adjust');
        });
    }
};
