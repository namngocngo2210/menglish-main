<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The existing unique index already forbids duplicates; never deduplicate attendance by deleting rows.
        $this->assertNoDuplicates();

        Schema::table('teacher_timesheets', function (Blueprint $table) {
            $table->string('reconcile_status', 20)->nullable();
            $table->boolean('bi_xoa_boi_hoc_vu')->default(false);
            $table->foreignId('soft_deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('soft_deleted_at')->nullable();
            $table->text('soft_delete_reason')->nullable();
            $table->boolean('payroll_locked')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['reconcile_status', 'teaching_date'], 'teacher_timesheets_reconcile_date_index');
        });
        // Keep uniqueness enforced throughout the index replacement (including MySQL's user_id foreign key).
        Schema::table('teacher_timesheets', function (Blueprint $table) {
            $table->unique(['user_id', 'class_session_id', 'bi_xoa_boi_hoc_vu'], 'teacher_timesheet_user_session_active_unique');
        });
        Schema::table('teacher_timesheets', function (Blueprint $table) {
            $table->dropUnique('teacher_timesheet_user_session_unique');
        });
    }

    public function down(): void
    {
        // A live/deleted pair cannot fit the old unique index: refuse rollback instead of deleting either row.
        $this->assertNoDuplicates();
        Schema::table('teacher_timesheets', function (Blueprint $table) {
            $table->unique(['user_id', 'class_session_id'], 'teacher_timesheet_user_session_unique');
        });
        Schema::table('teacher_timesheets', function (Blueprint $table) {
            $table->dropUnique('teacher_timesheet_user_session_active_unique');
            $table->dropIndex('teacher_timesheets_reconcile_date_index');
            $table->dropConstrainedForeignId('soft_deleted_by');
            $table->dropConstrainedForeignId('created_by');
            // Rollback drops reconciliation and deletion metadata, including any set statuses/deletion flags.
            $table->dropColumn(['reconcile_status', 'bi_xoa_boi_hoc_vu', 'soft_deleted_at', 'soft_delete_reason', 'payroll_locked']);
        });
    }

    private function assertNoDuplicates(): void
    {
        $duplicates = DB::table('teacher_timesheets')->select('user_id', 'class_session_id')
            ->whereNotNull('class_session_id')->groupBy('user_id', 'class_session_id')->havingRaw('COUNT(*) > 1')->get();
        if ($duplicates->isEmpty()) {
            return;
        }
        $ids = $duplicates->flatMap(fn ($pair) => DB::table('teacher_timesheets')
            ->where('user_id', $pair->user_id)->where('class_session_id', $pair->class_session_id)->orderBy('id')->pluck('id'));

        throw new RuntimeException('Cannot replace teacher timesheet unique index: duplicate row ids: '.$ids->implode(', '));
    }
};
