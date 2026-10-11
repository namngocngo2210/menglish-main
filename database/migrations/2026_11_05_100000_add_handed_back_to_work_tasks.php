<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Việc bị chặn được chuyển lại cho người giao (ticket 53, 10/10/2026): ghi người đang làm trước đó và lúc chuyển,
 * để chi tiết / danh sách hiện "Chuyển lại từ …" và người làm cũ vẫn mở được việc.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('work_tasks', 'handed_back_from_id')) {
            return;
        }

        Schema::table('work_tasks', function (Blueprint $table) {
            $table->foreignId('handed_back_from_id')->nullable()->after('assignee_id')
                ->constrained('users', indexName: 'work_tasks_handed_back_from_fk')->nullOnDelete();
            $table->timestamp('handed_back_at')->nullable()->after('handed_back_from_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('work_tasks', 'handed_back_from_id')) {
            return;
        }

        Schema::table('work_tasks', function (Blueprint $table) {
            $table->dropForeign('work_tasks_handed_back_from_fk');
            $table->dropColumn(['handed_back_from_id', 'handed_back_at']);
        });
    }
};
