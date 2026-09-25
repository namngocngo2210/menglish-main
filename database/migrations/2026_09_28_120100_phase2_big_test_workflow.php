<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Big Test: bảng order đề của giáo viên (thay cho AcademicRecord ORDTEST-),
 * cờ vắng thi + link video cho kết quả, mốc đã nhắc giáo viên trước 7 ngày.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('big_test_orders', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stage_name');
            $table->string('test_type', 10)->default('big'); // mini, big
            $table->date('exam_date')->nullable();   // Ngày thi dự kiến giáo viên đề nghị
            $table->date('due_date')->nullable();    // Hạn xử lý (phân phối đề trước ngày thi 3 ngày)
            $table->text('note')->nullable();
            $table->string('status', 20)->default('pending'); // pending, approved, rejected
            $table->string('test_link', 500)->nullable();
            $table->foreignId('big_test_id')->nullable()->constrained('big_tests')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_date']);
        });

        // Chuyển các order cũ (lưu tạm trong academic_records) sang bảng mới.
        $legacy = DB::table('academic_records')->where('screen_key', '03_Cong_Giao_Vien/09_order_test')->get();
        foreach ($legacy as $record) {
            $data = json_decode((string) $record->data, true) ?: [];
            $classId = (int) ($data['class_id'] ?? 0);
            if (! $classId || ! DB::table('classes')->where('id', $classId)->exists()) {
                continue;
            }
            $teacherId = (int) ($data['teacher_id'] ?? $record->user_id ?? 0);
            DB::table('big_test_orders')->insertOrIgnore([
                'code' => $record->record_code ?: 'ORDTEST-'.$record->id,
                'class_id' => $classId,
                'teacher_id' => $teacherId && DB::table('users')->where('id', $teacherId)->exists() ? $teacherId : null,
                'stage_name' => (string) ($data['stage_name'] ?? 'Chặng'),
                'test_type' => ($data['test_type'] ?? 'big') === 'mini' ? 'mini' : 'big',
                'note' => $data['note'] ?? null,
                'status' => in_array($record->status, ['approved', 'rejected'], true) ? $record->status : 'pending',
                'created_at' => $record->created_at,
                'updated_at' => $record->updated_at,
            ]);
        }

        Schema::table('big_test_results', function (Blueprint $table) {
            // Vắng thi được đánh dấu rõ ràng thay vì lưu điểm 0.
            $table->decimal('listening_score', 4, 1)->nullable()->default(null)->change();
            $table->decimal('reading_score', 4, 1)->nullable()->default(null)->change();
            $table->decimal('writing_score', 4, 1)->nullable()->default(null)->change();
            $table->decimal('speaking_score', 4, 1)->nullable()->default(null)->change();
            $table->decimal('overall_score', 4, 1)->nullable()->default(null)->change();
            $table->boolean('is_absent')->default(false)->after('overall_score');
            $table->string('video_url', 500)->nullable()->after('progress_note');
        });

        Schema::table('big_tests', function (Blueprint $table) {
            $table->timestamp('teacher_reminded_at')->nullable()->after('distributed_at');
        });
    }

    public function down(): void
    {
        Schema::table('big_tests', function (Blueprint $table) {
            $table->dropColumn('teacher_reminded_at');
        });

        DB::table('big_test_results')->whereNull('listening_score')->update(['listening_score' => 0]);
        DB::table('big_test_results')->whereNull('reading_score')->update(['reading_score' => 0]);
        DB::table('big_test_results')->whereNull('writing_score')->update(['writing_score' => 0]);
        DB::table('big_test_results')->whereNull('speaking_score')->update(['speaking_score' => 0]);
        DB::table('big_test_results')->whereNull('overall_score')->update(['overall_score' => 0]);

        Schema::table('big_test_results', function (Blueprint $table) {
            $table->dropColumn(['is_absent', 'video_url']);
            $table->decimal('listening_score', 4, 1)->nullable(false)->default(0)->change();
            $table->decimal('reading_score', 4, 1)->nullable(false)->default(0)->change();
            $table->decimal('writing_score', 4, 1)->nullable(false)->default(0)->change();
            $table->decimal('speaking_score', 4, 1)->nullable(false)->default(0)->change();
            $table->decimal('overall_score', 4, 1)->nullable(false)->default(0)->change();
        });

        Schema::dropIfExists('big_test_orders');
    }
};
