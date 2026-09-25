<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cấu hình chỉ số KPI (trọng số) cho nhân sự học vụ
        Schema::create('kpi_criteria', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('weight', 5, 2)->default(0); // trọng số %
            $table->string('target')->nullable(); // mục tiêu (mô tả)
            $table->string('unit')->nullable(); // đơn vị
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Đánh giá KPI theo tháng cho từng nhân sự
        Schema::create('kpi_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('evaluator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('month');
            $table->integer('year');
            $table->decimal('total_score', 6, 2)->default(0);
            $table->text('comment')->nullable();
            $table->string('status', 20)->default('draft'); // draft, confirmed
            $table->timestamps();

            $table->unique(['user_id', 'month', 'year']);
        });

        // Điểm chi tiết theo từng tiêu chí
        Schema::create('kpi_evaluation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_evaluation_id')->constrained('kpi_evaluations')->cascadeOnDelete();
            $table->foreignId('kpi_criterion_id')->constrained('kpi_criteria')->cascadeOnDelete();
            $table->decimal('score', 6, 2)->default(0); // 0-100 (% đạt)
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['kpi_evaluation_id', 'kpi_criterion_id'], 'kpi_eval_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_evaluation_items');
        Schema::dropIfExists('kpi_evaluations');
        Schema::dropIfExists('kpi_criteria');
    }
};
