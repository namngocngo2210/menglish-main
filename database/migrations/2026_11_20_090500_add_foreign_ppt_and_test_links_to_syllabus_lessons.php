<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Form Sheet 1 mới của Học thuật (docs/kho-syllabus.md §3.3) thêm 2 cột link: PPT cho giáo viên nước ngoài và đề
 * Mini test / Kiểm tra từ. Cột nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('syllabus_lessons', function (Blueprint $table) {
            $table->string('ppt_foreign_url', 500)->nullable();
            $table->string('test_url', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('syllabus_lessons', function (Blueprint $table) {
            $table->dropColumn(['ppt_foreign_url', 'test_url']);
        });
    }
};
