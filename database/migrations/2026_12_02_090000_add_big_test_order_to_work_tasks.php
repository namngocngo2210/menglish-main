<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * work_tasks.big_test_order_id: việc tự tạo cho Học thuật khi giáo viên order đề mini test / Big Test theo mốc syllabus
     * (1 việc / order, tự đóng khi order được duyệt / từ chối).
     */
    public function up(): void
    {
        Schema::table('work_tasks', function (Blueprint $table) {
            $table->foreignId('big_test_order_id')->nullable()->after('big_test_id')->constrained('big_test_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('big_test_order_id');
        });
    }
};
