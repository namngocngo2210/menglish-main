<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Việc "Nhập bù sách" tự giao cho Admin khi tồn kho một mặt hàng ở chi nhánh về 0 hoặc âm
 * (work_tasks.kind = merchandise_restock). Cột mặt hàng để không tạo trùng việc cho cùng chi nhánh + mặt hàng
 * và tự đóng việc khi chi nhánh nhập kho đủ. Tên khóa ngoại / index đặt ngắn (MySQL giới hạn 64 ký tự).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('work_tasks', 'merchandise_item_id')) {
            return;
        }

        Schema::table('work_tasks', function (Blueprint $table) {
            $table->foreignId('merchandise_item_id')->nullable()->after('big_test_id')
                ->constrained('merchandise_items', indexName: 'work_tasks_merch_item_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('work_tasks', 'merchandise_item_id')) {
            return;
        }

        Schema::table('work_tasks', function (Blueprint $table) {
            $table->dropForeign('work_tasks_merch_item_fk');
            $table->dropColumn('merchandise_item_id');
        });
    }
};
