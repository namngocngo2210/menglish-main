<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_task_series')) {
            Schema::create('work_task_series', function (Blueprint $table) {
                $table->id();
                $table->decimal('penalty_amount', 12, 2)->nullable();
                $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('configured_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('work_tasks', 'series_id')) {
            Schema::table('work_tasks', function (Blueprint $table) {
                $table->foreignId('series_id')->nullable()->index()->constrained('work_task_series')->nullOnDelete();
            });
        }

        $this->backfill();
    }

    public function backfill(): void
    {
        // Use raw rows, including soft-deleted occurrences, without model events or audit entries.
        DB::table('work_tasks')->where('task_type', 'recurring')->whereNull('series_id')
            ->select(['id', 'title', 'assignee_id', 'frequency'])->chunkById(200, function ($tasks) {
                DB::transaction(function () use ($tasks) {
                    foreach ($tasks as $task) {
                        $seriesId = DB::table('work_tasks')->where('task_type', 'recurring')
                            ->where('title', $task->title)->where('assignee_id', $task->assignee_id)
                            ->where('frequency', $task->frequency)->whereNotNull('series_id')->value('series_id');

                        if ($seriesId === null) {
                            $seriesId = DB::table('work_task_series')->insertGetId([
                                'penalty_amount' => null, 'configured_by' => null, 'configured_at' => null,
                                'created_at' => now(), 'updated_at' => now(),
                            ]);
                        }

                        DB::table('work_tasks')->where('id', $task->id)->whereNull('series_id')->update(['series_id' => $seriesId]);
                    }
                });
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('work_tasks', 'series_id')) {
            Schema::table('work_tasks', function (Blueprint $table) {
                $table->dropForeign(['series_id']);
                $table->dropIndex(['series_id']);
                $table->dropColumn('series_id');
            });
        }

        Schema::dropIfExists('work_task_series');
    }
};
