<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_customers', function (Blueprint $table) {
            $table->foreignId('trial_class_id')->nullable()->after('trial_teacher_id')->constrained('classes')->nullOnDelete();
            $table->string('trial_mode', 20)->nullable()->after('trial_class_id');
            $table->string('trial_status', 30)->nullable()->after('trial_mode');
            $table->text('trial_notes')->nullable()->after('trial_feedback');
            $table->foreignId('waiting_course_id')->nullable()->after('waiting_since')->constrained('courses')->nullOnDelete();
            $table->foreignId('waiting_branch_id')->nullable()->after('waiting_course_id')->constrained('branches')->nullOnDelete();
            $table->date('desired_start_date')->nullable()->after('preferred_schedule');
            $table->unsignedTinyInteger('waiting_priority')->default(3)->after('desired_start_date');
            $table->text('waiting_notes')->nullable()->after('waiting_priority');
            $table->dateTime('lost_at')->nullable()->after('lost_reason')->index();
        });

        Schema::table('promotions', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('description')->constrained('branches')->nullOnDelete();
            $table->foreignId('course_id')->nullable()->after('branch_id')->constrained('courses')->nullOnDelete();
            $table->dateTime('starts_at')->nullable()->after('course_id');
            $table->dateTime('ends_at')->nullable()->after('starts_at');
            $table->unsignedInteger('usage_limit')->nullable()->after('ends_at');
            $table->unsignedInteger('used_count')->default(0)->after('usage_limit');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('course_id');
            $table->dropColumn(['starts_at', 'ends_at', 'usage_limit', 'used_count']);
        });

        Schema::table('crm_customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trial_class_id');
            $table->dropConstrainedForeignId('waiting_course_id');
            $table->dropConstrainedForeignId('waiting_branch_id');
            $table->dropIndex(['lost_at']);
            $table->dropColumn([
                'trial_mode', 'trial_status', 'trial_notes', 'desired_start_date',
                'waiting_priority', 'waiting_notes', 'lost_at',
            ]);
        });
    }
};
