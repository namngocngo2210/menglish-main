<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kho syllabus (docs/kho-syllabus.md §3.2): trường buổi học theo form Sheet 1 (loại buổi, quy trình, hoạt động gợi ý,
 * homework 3 giỏ — homework_guide có sẵn là "Bài tập giấy", link PPT / Quizizz, sản phẩm đầu ra, mốc thông báo, ghi chú)
 * và số buổi báo trước cuối chặng. Mọi cột mới nullable / có mặc định.
 */
return new class extends Migration
{
    private const LESSON_TEXT = ['procedure', 'activity_1', 'activity_2', 'homework_quizizz', 'homework_video', 'output_product', 'note'];

    public function up(): void
    {
        Schema::table('syllabus_lessons', function (Blueprint $table) {
            $table->string('lesson_type', 20)->default('lesson')->after('title');
            foreach (self::LESSON_TEXT as $column) {
                $table->text($column)->nullable();
            }
            $table->string('ppt_url', 500)->nullable();
            $table->string('quizizz_url', 500)->nullable();
            $table->string('notice_milestone', 30)->nullable();
        });

        Schema::table('syllabus_stages', function (Blueprint $table) {
            $table->unsignedSmallInteger('notice_before_sessions')->default(6)->after('big_test_note');
        });
    }

    public function down(): void
    {
        Schema::table('syllabus_stages', function (Blueprint $table) {
            $table->dropColumn('notice_before_sessions');
        });

        Schema::table('syllabus_lessons', function (Blueprint $table) {
            $table->dropColumn(['lesson_type', ...self::LESSON_TEXT, 'ppt_url', 'quizizz_url', 'notice_milestone']);
        });
    }
};
