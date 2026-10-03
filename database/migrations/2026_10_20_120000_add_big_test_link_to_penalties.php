<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Biên bản tự lập từ Big Test (trả kết quả trễ, GV chưa nhận đề):
     * - penalties.big_test_id: biên bản thuộc đợt thi nào.
     * - penalties.auto_source: loại biên bản tự động, cùng big_test_id (+ user_id) để lập idempotent.
     */
    public function up(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->foreignId('big_test_id')->nullable()->after('work_task_id')->constrained('big_tests')->nullOnDelete();
            $table->string('auto_source', 40)->nullable()->after('big_test_id');
            $table->index(['big_test_id', 'auto_source']);
        });
    }

    public function down(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->dropIndex(['big_test_id', 'auto_source']);
            $table->dropConstrainedForeignId('big_test_id');
            $table->dropColumn('auto_source');
        });
    }
};
