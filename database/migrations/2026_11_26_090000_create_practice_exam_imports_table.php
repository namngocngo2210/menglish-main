<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lần import đề cố định chạy nền (docs/luyen-tap-ngan-hang-cau-hoi.md §11.2): trạng thái + kết quả để màn Đề cố định theo dõi. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_exam_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('queued'); // queued | running | done | failed
            $table->json('file_names');
            $table->string('message')->nullable();
            $table->json('report')->nullable(); // saved / kept / errors / warnings
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_exam_imports');
    }
};
