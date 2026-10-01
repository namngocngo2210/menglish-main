<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order học liệu: giáo viên đặt đạo cụ / in ấn / học liệu GVNN / học liệu học thuật; Học vụ (CM) hoặc Trưởng Học thuật
 * xử lý trước hạn `due_at` (tính theo loại + ngày sử dụng, xem config/material_orders.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('requester_id')->constrained('users');
            $table->string('category', 30)->index(); // props | printing | foreign_teacher | academic
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->date('use_date');
            $table->dateTime('due_at')->index();
            $table->string('status', 20)->default('pending')->index(); // pending | processing | done | rejected | overdue
            $table->boolean('created_late')->default(false);
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('processed_at')->nullable();
            $table->boolean('processed_late')->default(false);
            $table->text('processor_note')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_orders');
    }
};
