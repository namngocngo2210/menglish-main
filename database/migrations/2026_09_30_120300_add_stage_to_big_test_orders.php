<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order đề của GV lấy đúng chặng đang mở của lớp (Q4) thay cho tên chặng nhập tự do.
 * Order cũ giữ `stage_name` để hiển thị; syllabus_stage_id NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('big_test_orders', function (Blueprint $table) {
            $table->foreignId('syllabus_stage_id')->nullable()->after('class_id')->constrained('syllabus_stages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('big_test_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('syllabus_stage_id');
        });
    }
};
