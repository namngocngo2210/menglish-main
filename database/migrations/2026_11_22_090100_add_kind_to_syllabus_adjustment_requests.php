<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Customize syllabus (docs/kho-syllabus.md §8.4): loại yêu cầu — `extend` giãn thêm buổi vào TKB, `postpone` dời tiến độ. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('syllabus_adjustment_requests', function (Blueprint $table) {
            $table->string('kind', 20)->default('extend')->after('request_type');
        });
    }

    public function down(): void
    {
        Schema::table('syllabus_adjustment_requests', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
