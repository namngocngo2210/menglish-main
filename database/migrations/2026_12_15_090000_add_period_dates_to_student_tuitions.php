<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TK-2026-0116: đợt học phí có ngày bắt đầu / kết thúc (khoản cũ để trống = đợt đầu, xếp trước). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_tuitions', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('session_count');
            $table->date('end_date')->nullable()->after('start_date');
        });
    }

    public function down(): void
    {
        Schema::table('student_tuitions', function (Blueprint $table) {
            $table->dropColumn(['start_date', 'end_date']);
        });
    }
};
