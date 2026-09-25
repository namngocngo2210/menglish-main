<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bộ đếm dùng chung để sinh mã chứng từ (HV-, BT-, TK-...) — xem App\Services\DocumentCodeGenerator.
 * Mỗi dòng là một dãy số theo (key, period); period rỗng = dãy không reset theo kỳ.
 * Không cần seed: dãy được khởi tạo lười từ mã lớn nhất đang có ở lần sinh mã đầu tiên.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50);
            $table->string('period', 20)->default('');
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();

            $table->unique(['key', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
