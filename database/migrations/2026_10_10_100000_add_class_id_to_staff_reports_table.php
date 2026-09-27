<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sự vụ (nhật ký, staff_reports.type = journal) gắn với một lớp để Trang lớp có tab "Sự vụ" và màn Báo cáo & sự vụ
 * lọc được theo lớp. Nullable: sự vụ chung của cơ sở không thuộc lớp nào.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_reports', function (Blueprint $table) {
            $table->foreignId('class_id')->nullable()->after('user_id')->constrained('classes')->nullOnDelete();
            $table->index(['class_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('staff_reports', function (Blueprint $table) {
            $table->dropIndex(['class_id', 'type']);
            $table->dropConstrainedForeignId('class_id');
        });
    }
};
