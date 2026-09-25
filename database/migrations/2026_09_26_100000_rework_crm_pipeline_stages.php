<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pipeline CRM 8 bước (BA chốt 2026-09-25):
 * new → consulting → test_scheduled → testing → tested → result_sent → waiting_class → won (+ lost).
 *
 * - Bỏ các stage trial_scheduled / trial_completed / closing; dữ liệu dev được quy đổi:
 *     + lead đã có hồ sơ học viên (converted_student_id) → won;
 *     + trial_* / closing / waiting_class (chưa có hồ sơ học viên) → result_sent nếu đã có điểm test,
 *       ngược lại → consulting. Chờ xếp lớp giờ nghĩa là "đã chốt, chưa có lớp" nên lead cũ ở
 *       waiting_class chưa chốt phải quay về bước tư vấn.
 * - Học thử không còn là stage: chuyển sang bảng crm_trial_bookings (gắn lead + buổi học thật),
 *   bỏ các cột trial_* trên crm_customers (hệ thống chưa go-live, không backfill).
 * - Lịch sử chuyển bước có from/to/lý do để audit (lùi bước chỉ Admin, bắt buộc lý do).
 * - Cờ "Đã đóng học phí đăng ký" lúc chốt.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('crm_customers')->whereNotNull('converted_student_id')->update(['stage' => 'won']);
        DB::table('crm_customers')
            ->whereNull('converted_student_id')
            ->whereIn('stage', ['trial_scheduled', 'trial_completed', 'closing', 'waiting_class'])
            ->update(['stage' => DB::raw("CASE WHEN test_score IS NOT NULL AND test_score <> '' THEN 'result_sent' ELSE 'consulting' END")]);

        Schema::table('crm_customers', function (Blueprint $table) {
            $table->boolean('fee_paid_at_closing')->nullable()->after('converted_at');
            $table->index(['branch_id', 'stage']);
            $table->index(['assigned_user_id', 'stage']);
        });

        Schema::table('crm_customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trial_teacher_id');
            $table->dropConstrainedForeignId('trial_class_id');
        });
        Schema::table('crm_customers', function (Blueprint $table) {
            $table->dropColumn(['trial_at', 'trial_mode', 'trial_status', 'trial_rating', 'trial_feedback', 'trial_notes']);
        });

        Schema::table('crm_customer_histories', function (Blueprint $table) {
            $table->string('from_stage', 30)->nullable()->after('content');
            $table->string('to_stage', 30)->nullable()->after('from_stage');
            $table->text('reason')->nullable()->after('to_stage');
            $table->index(['customer_id', 'type']);
        });

        Schema::create('crm_trial_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('crm_customers')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('class_session_id')->constrained('class_sessions')->cascadeOnDelete();
            $table->foreignId('booked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('scheduled'); // scheduled, attended, no_show, cancelled
            $table->text('notes')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('feedback_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('feedback_at')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'class_session_id']);
            $table->index(['class_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_trial_bookings');

        Schema::table('crm_customer_histories', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'type']);
            $table->dropColumn(['from_stage', 'to_stage', 'reason']);
        });

        Schema::table('crm_customers', function (Blueprint $table) {
            $table->dateTime('trial_at')->nullable();
            $table->foreignId('trial_teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('trial_class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->string('trial_mode', 20)->nullable();
            $table->string('trial_status', 30)->nullable();
            $table->unsignedTinyInteger('trial_rating')->nullable();
            $table->text('trial_feedback')->nullable();
            $table->text('trial_notes')->nullable();
            $table->dropIndex(['branch_id', 'stage']);
            $table->dropIndex(['assigned_user_id', 'stage']);
            $table->dropColumn('fee_paid_at_closing');
        });
    }
};
