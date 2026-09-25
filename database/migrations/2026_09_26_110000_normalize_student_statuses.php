<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trạng thái hồ sơ học viên theo BA (6 trạng thái): waiting_start (Chờ khai giảng), studying (Đang học),
 * deferred (Bảo lưu), summer_break (Nghỉ hè), completed (Hoàn thành khóa học), dropped (Thôi học).
 * Quy đổi dữ liệu dev: graduated → completed, active/trial/giá trị lạ → studying; mặc định cột = waiting_start.
 */
return new class extends Migration
{
    private const STATUSES = ['waiting_start', 'studying', 'deferred', 'summer_break', 'completed', 'dropped'];

    public function up(): void
    {
        DB::table('students')->where('status', 'graduated')->update(['status' => 'completed']);
        DB::table('students')
            ->where(fn ($query) => $query->whereNull('status')->orWhereNotIn('status', self::STATUSES))
            ->update(['status' => 'studying']);

        Schema::table('students', function (Blueprint $table) {
            $table->string('status', 50)->default('waiting_start')->change();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->string('status', 50)->default('studying')->change();
        });
        DB::table('students')->where('status', 'completed')->update(['status' => 'graduated']);
        DB::table('students')->whereIn('status', ['waiting_start', 'summer_break'])->update(['status' => 'studying']);
    }
};
