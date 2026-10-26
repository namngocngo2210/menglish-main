<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bỏ mục "Đã vào nhóm Zalo lớp" khỏi checklist bàn giao / xác nhận nhập học. Lượt xếp lớp trực tiếp (không qua CRM)
 * đang chờ chỉ vì chưa tick Zalo mà đã phát giáo trình → hoàn tất, như quy tắc mới (chỉ cần giáo trình).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('class_enrollments')
            ->where('status', 'pending')
            ->whereNull('customer_id')
            ->where('curriculum_delivered', true)
            ->update(['status' => 'completed']);

        Schema::table('class_enrollments', function (Blueprint $table) {
            $table->dropColumn('zalo_group_added');
        });
    }

    public function down(): void
    {
        Schema::table('class_enrollments', function (Blueprint $table) {
            $table->boolean('zalo_group_added')->default(false)->after('curriculum_delivered');
        });
    }
};
