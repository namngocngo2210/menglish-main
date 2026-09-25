<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Q4 (BA chốt 25/09/2026) — Mô hình giáo trình phân cấp:
 *   Giáo trình (gắn Trình độ) → Chặng (Big Test cuối chặng) → Unit → Buổi (nội dung từng buổi).
 * Mỗi lớp chỉ có 1 chặng đang mở; chặng đóng khi Big Test của chặng được duyệt và gửi PH, chặng kế tiếp tự mở.
 *
 * Bảng:
 *  - syllabus_stages (mới): chặng của giáo trình, thứ tự `position`, thông tin Big Test cuối chặng.
 *  - syllabus_units (giữ nguyên, thêm stage_id): Unit = nhóm buổi, đánh số `unit_number` duy nhất trong giáo trình.
 *  - syllabus_lessons (mới): Buổi, đánh số `session_no` duy nhất trong giáo trình (buổi thứ N của lớp ↔ session_no N).
 *  - syllabus_assignments: bản ghi "chặng của lớp" (mở/đóng, ai mở/đóng, lý do, Big Test đã đóng chặng);
 *    `open_class_id` = class_id khi đang mở, NULL khi đóng — UNIQUE để DB chặn 2 chặng mở cùng lúc.
 *  - big_tests.syllabus_stage_id: đợt thi thuộc chặng nào (kèm class_id sẵn có).
 *
 * Chuyển dữ liệu cũ (không mất dữ liệu):
 *  - Mỗi giáo trình → Chặng 1 (tên = syllabus_curriculums.stage_name hoặc "Chặng 1", link tổng quan giữ nguyên).
 *  - Mỗi dòng syllabus_units cũ (màn Soạn syllabus cũ gọi là "Buổi số N") → giữ nguyên id làm Unit N của Chặng 1
 *    và sinh đúng 1 Buổi session_no = N (trùng số thì lấy số trống kế tiếp) chép nguyên nội dung.
 *    Id unit giữ nguyên nên đề xuất sửa giáo trình (unit_id) vẫn trỏ đúng.
 *  - Chặng đã giao cho lớp → gắn Chặng 1 của giáo trình; lớp có nhiều chặng `in_progress` thì giữ bản mới nhất,
 *    các bản cũ đóng lại kèm lý do.
 *  - Big Test chưa gửi hết kết quả của lớp đang có chặng mở → gắn vào chặng đó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('syllabus_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_id')->constrained('syllabus_curriculums')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(1);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('overview_link', 500)->nullable();
            $table->string('big_test_title')->nullable();
            $table->text('big_test_note')->nullable();
            $table->timestamps();
            $table->index(['curriculum_id', 'position']);
        });

        Schema::table('syllabus_units', function (Blueprint $table) {
            $table->foreignId('stage_id')->nullable()->after('curriculum_id')->constrained('syllabus_stages')->cascadeOnDelete();
        });

        Schema::create('syllabus_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_id')->constrained('syllabus_curriculums')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('syllabus_units')->cascadeOnDelete();
            $table->unsignedSmallInteger('session_no');
            $table->string('title');
            $table->text('objectives')->nullable();
            $table->text('vocabulary_focus')->nullable();
            $table->text('grammar_focus')->nullable();
            $table->text('homework_guide')->nullable();
            $table->timestamps();
            $table->unique(['curriculum_id', 'session_no'], 'syllabus_lessons_curriculum_session_unique');
        });

        Schema::table('syllabus_assignments', function (Blueprint $table) {
            $table->foreignId('stage_id')->nullable()->after('curriculum_id')->constrained('syllabus_stages')->nullOnDelete();
            $table->unsignedBigInteger('open_class_id')->nullable()->after('status');
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('open_reason')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('close_reason')->nullable();
            $table->foreignId('closed_by_big_test_id')->nullable()->constrained('big_tests')->nullOnDelete();
            $table->timestamp('curriculum_completed_at')->nullable();
            $table->unsignedSmallInteger('extra_sessions')->default(0);
        });

        Schema::table('big_tests', function (Blueprint $table) {
            $table->foreignId('syllabus_stage_id')->nullable()->after('class_id')->constrained('syllabus_stages')->nullOnDelete();
            $table->timestamp('results_completed_at')->nullable();
        });

        Schema::table('syllabus_adjustment_requests', function (Blueprint $table) {
            $table->foreignId('syllabus_assignment_id')->nullable()->after('class_id')->constrained('syllabus_assignments')->nullOnDelete();
        });

        $this->migrateLegacyData();

        Schema::table('syllabus_assignments', function (Blueprint $table) {
            $table->unique('open_class_id', 'syllabus_assignments_one_open_stage_per_class');
        });
    }

    private function migrateLegacyData(): void
    {
        $now = now();
        $stageByCurriculum = [];

        foreach (DB::table('syllabus_curriculums')->orderBy('id')->get() as $curriculum) {
            $stageByCurriculum[$curriculum->id] = DB::table('syllabus_stages')->insertGetId([
                'curriculum_id' => $curriculum->id,
                'position' => 1,
                'name' => trim((string) $curriculum->stage_name) !== '' ? $curriculum->stage_name : 'Chặng 1',
                'overview_link' => $curriculum->overview_link,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Unit cũ → Unit của Chặng 1 + 1 Buổi cùng số, chép nội dung.
        $usedSessions = [];
        $units = DB::table('syllabus_units')->orderBy('curriculum_id')->orderBy('unit_number')->orderBy('id')->get();
        foreach ($units as $unit) {
            $stageId = $stageByCurriculum[$unit->curriculum_id] ?? null;
            if (! $stageId) {
                continue;
            }
            DB::table('syllabus_units')->where('id', $unit->id)->update(['stage_id' => $stageId]);

            $sessionNo = max(1, (int) $unit->unit_number);
            while (isset($usedSessions[$unit->curriculum_id][$sessionNo])) {
                $sessionNo++;
            }
            $usedSessions[$unit->curriculum_id][$sessionNo] = true;

            DB::table('syllabus_lessons')->insert([
                'curriculum_id' => $unit->curriculum_id,
                'unit_id' => $unit->id,
                'session_no' => $sessionNo,
                'title' => $unit->title,
                'objectives' => $unit->objectives,
                'vocabulary_focus' => $unit->vocabulary_focus,
                'grammar_focus' => $unit->grammar_focus,
                'homework_guide' => $unit->homework_guide,
                'created_at' => $unit->created_at ?? $now,
                'updated_at' => $unit->updated_at ?? $now,
            ]);
        }

        // Chặng đã giao: gắn Chặng 1, ghi mốc mở/đóng, mỗi lớp chỉ giữ 1 chặng mở.
        $openByClass = [];
        foreach (DB::table('syllabus_assignments')->orderByDesc('id')->get() as $assignment) {
            $update = [
                'stage_id' => $stageByCurriculum[$assignment->curriculum_id] ?? null,
                'opened_at' => $assignment->created_at,
            ];
            if ($assignment->status === 'in_progress') {
                if ($assignment->class_id && isset($openByClass[$assignment->class_id])) {
                    $update += [
                        'status' => 'completed',
                        'closed_at' => $now,
                        'close_reason' => 'Tự đóng khi chuyển sang mô hình "mỗi lớp 1 chặng đang mở" (lớp có nhiều chặng đang áp dụng).',
                    ];
                } elseif ($assignment->class_id) {
                    $openByClass[$assignment->class_id] = $assignment;
                    $update['open_class_id'] = $assignment->class_id;
                }
            } elseif ($assignment->status === 'completed') {
                $update['closed_at'] = $assignment->updated_at;
            }
            DB::table('syllabus_assignments')->where('id', $assignment->id)->update($update);
        }

        // Big Test chưa gửi hết kết quả của lớp đang mở chặng → thuộc chặng đang mở.
        foreach ($openByClass as $classId => $assignment) {
            $stageId = $stageByCurriculum[$assignment->curriculum_id] ?? null;
            if (! $stageId) {
                continue;
            }
            $tests = DB::table('big_tests')->where('class_id', $classId)->whereNull('syllabus_stage_id')->pluck('id');
            foreach ($tests as $testId) {
                $results = DB::table('big_test_results')->where('big_test_id', $testId);
                $allSent = (clone $results)->exists() && ! (clone $results)->where('status', '!=', 'sent')->exists();
                if (! $allSent) {
                    DB::table('big_tests')->where('id', $testId)->update(['syllabus_stage_id' => $stageId]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('syllabus_assignments', function (Blueprint $table) {
            $table->dropUnique('syllabus_assignments_one_open_stage_per_class');
        });
        Schema::table('syllabus_adjustment_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('syllabus_assignment_id');
        });
        Schema::table('big_tests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('syllabus_stage_id');
            $table->dropColumn('results_completed_at');
        });
        Schema::table('syllabus_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stage_id');
            $table->dropConstrainedForeignId('opened_by');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropConstrainedForeignId('closed_by_big_test_id');
            $table->dropColumn(['open_class_id', 'opened_at', 'open_reason', 'closed_at', 'close_reason', 'curriculum_completed_at', 'extra_sessions']);
        });
        Schema::dropIfExists('syllabus_lessons');
        Schema::table('syllabus_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stage_id');
        });
        Schema::dropIfExists('syllabus_stages');
    }
};
