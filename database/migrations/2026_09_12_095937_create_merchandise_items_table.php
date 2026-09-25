<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('merchandise_items', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 255);
            $table->string('category', 50)->default('book'); // book, workbook, uniform, backpack, equipment, gift, other
            $table->string('unit', 50)->default('Bộ'); // Bộ, Cuốn, Chiếc, Cái, Hộp...
            $table->decimal('price', 14, 2)->default(0); // Đơn giá niêm yết bán / tính vào phiếu thu
            $table->decimal('cost_price', 14, 2)->nullable(); // Giá vốn
            $table->integer('stock_quantity')->default(0); // Số lượng tồn kho
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchandise_items');
    }
};
