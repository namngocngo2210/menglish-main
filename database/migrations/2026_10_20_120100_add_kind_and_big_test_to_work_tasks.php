<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - work_tasks.kind = 'ta_daily': nhiệm vụ hằng ngày của trợ giảng (CV-05) — cố ý KHÔNG có hạn nên không bị quét quá hạn
     *   (vẫn giữ due_date = ngày làm việc). Việc cũ giao qua "Giao nhiệm vụ cho TA" (mô tả "Nhiệm vụ trực ca …") được đánh dấu sẵn;
     *   việc đang "Quá hạn" chỉ vì hạn giờ này trả về "Mới".
     * - work_tasks.big_test_id: việc tự tạo cho Học thuật duyệt đề Big Test (idempotent theo đợt thi).
     */
    public function up(): void
    {
        Schema::table('work_tasks', function (Blueprint $table) {
            $table->string('kind', 30)->nullable()->after('task_type');
            $table->foreignId('big_test_id')->nullable()->after('student_id')->constrained('big_tests')->nullOnDelete();
            $table->index('kind');
        });

        DB::table('work_tasks')->where('description', 'like', 'Nhiệm vụ trực ca%')->update(['kind' => 'ta_daily']);
        DB::table('work_tasks')->where('kind', 'ta_daily')->where('status', 'overdue')->update(['status' => 'new']);
    }

    public function down(): void
    {
        Schema::table('work_tasks', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropConstrainedForeignId('big_test_id');
            $table->dropColumn('kind');
        });
    }
};
