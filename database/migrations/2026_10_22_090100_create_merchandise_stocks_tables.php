<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tồn kho hàng hóa (sách, đồng phục...) theo từng chi nhánh + nhật ký xuất nhập kho.
 *
 * Trước đây merchandise_items.stock_quantity là một số tồn chung cho cả hệ thống. Nếu hệ thống chỉ có 1 chi nhánh,
 * số tồn đó được chuyển hẳn về chi nhánh này. Có nhiều chi nhánh thì không biết sách đang nằm ở đâu: số cũ giữ ở
 * stock_quantity như "tồn cũ chưa phân chi nhánh", người quản lý kho phân bổ dần về từng chi nhánh khi nhập kho.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchandise_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchandise_item_id')->constrained('merchandise_items')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->timestamps();
            $table->unique(['merchandise_item_id', 'branch_id']);
        });

        Schema::create('merchandise_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchandise_item_id')->constrained('merchandise_items')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            // import | count | sale | return | opening
            $table->string('type', 20);
            // Xuất bán: contract (sách trong hợp đồng học phí) | surcharge (hàng chọn ở phần phụ thu của phiếu)
            $table->string('source', 20)->nullable();
            $table->integer('quantity_change');
            $table->integer('balance_after');
            $table->foreignId('tuition_receipt_id')->nullable()->constrained('tuition_receipts')->nullOnDelete();
            $table->foreignId('student_tuition_id')->nullable()->constrained('student_tuitions')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index(['merchandise_item_id', 'branch_id', 'created_at']);
            $table->index(['branch_id', 'created_at']);
        });

        $branchIds = DB::table('branches')
            ->when(Schema::hasColumn('branches', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->pluck('id');
        if ($branchIds->count() !== 1) {
            return;
        }

        $branchId = (int) $branchIds->first();
        $now = now();
        foreach (DB::table('merchandise_items')->where('stock_quantity', '>', 0)->get(['id', 'stock_quantity']) as $item) {
            DB::table('merchandise_stocks')->insert([
                'merchandise_item_id' => $item->id,
                'branch_id' => $branchId,
                'quantity' => (int) $item->stock_quantity,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('merchandise_stock_movements')->insert([
                'merchandise_item_id' => $item->id,
                'branch_id' => $branchId,
                'type' => 'opening',
                'quantity_change' => (int) $item->stock_quantity,
                'balance_after' => (int) $item->stock_quantity,
                'note' => 'Chuyển tồn kho chung cũ về chi nhánh',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('merchandise_items')->where('id', $item->id)->update(['stock_quantity' => 0]);
        }
    }

    public function down(): void
    {
        // Gộp tồn chi nhánh về lại số tồn chung trước khi xóa bảng.
        foreach (DB::table('merchandise_stocks')->selectRaw('merchandise_item_id, SUM(quantity) as total')->groupBy('merchandise_item_id')->get() as $row) {
            DB::table('merchandise_items')->where('id', $row->merchandise_item_id)->increment('stock_quantity', max(0, (int) $row->total));
        }

        Schema::dropIfExists('merchandise_stock_movements');
        Schema::dropIfExists('merchandise_stocks');
    }
};
