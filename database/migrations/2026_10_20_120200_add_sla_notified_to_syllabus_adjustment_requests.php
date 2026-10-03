<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Mốc đã báo người duyệt khi yêu cầu giãn tiến độ quá SLA 3 ngày (báo đúng 1 lần). */
    public function up(): void
    {
        Schema::table('syllabus_adjustment_requests', function (Blueprint $table) {
            $table->timestamp('sla_notified_at')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('syllabus_adjustment_requests', function (Blueprint $table) {
            $table->dropColumn('sla_notified_at');
        });
    }
};
