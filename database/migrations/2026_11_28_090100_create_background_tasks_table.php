<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Việc nặng chạy nền (import ngân hàng câu hỏi, import giáo trình, tính lương, import Excel...): trạng thái + kết quả để
 * màn khởi chạy theo dõi và báo người chạy khi xong (App\Models\BackgroundTask, App\Jobs\BackgroundTaskJob).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('background_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('queued'); // queued | running | done | failed
            $table->string('title');
            $table->json('payload')->nullable();
            $table->text('message')->nullable();
            $table->json('report')->nullable(); // errors / warnings / link
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('background_tasks');
    }
};
