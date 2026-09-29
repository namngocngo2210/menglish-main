<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SLA việc chăm sóc tháng đầu: việc quá hạn chưa xong → tự lập biên bản vi phạm cho người được giao (1 lần / việc).
     * - work_tasks.sla_breached_at: mốc đã xử lý quá SLA (đã lập biên bản), để không lập trùng.
     * - penalties.work_task_id: biên bản sinh ra từ việc nào.
     * Việc chăm sóc đã quá hạn từ trước khi có SLA được đánh dấu sẵn, không phạt hồi tố.
     */
    public function up(): void
    {
        Schema::table('work_tasks', function (Blueprint $table) {
            $table->timestamp('sla_breached_at')->nullable()->after('completed_at');
        });
        Schema::table('penalties', function (Blueprint $table) {
            $table->foreignId('work_task_id')->nullable()->after('class_id')->constrained('work_tasks')->nullOnDelete();
        });

        DB::table('work_tasks')
            ->whereNotNull('care_milestone')
            ->whereIn('status', ['new', 'in_progress', 'overdue'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->update(['sla_breached_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_task_id');
        });
        Schema::table('work_tasks', function (Blueprint $table) {
            $table->dropColumn('sla_breached_at');
        });
    }
};
