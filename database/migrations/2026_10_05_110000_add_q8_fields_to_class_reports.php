<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A6 Q8 (25/09/2026) — Báo cáo trực lớp:
 *  - board_images: nhiều ảnh bảng/lớp (≥ 1 ảnh → đầu việc "Trực lớp" tự Hoàn thành).
 *  - class_session_id: buổi học thật được báo cáo (tùy chọn).
 *  - confirmer_id: người phải xác nhận khi không có ảnh (GV chính của lớp; lớp chưa
 *    có GV chính → người giao việc), lưu lúc nộp để hiển thị / thông báo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_reports', function (Blueprint $table) {
            if (! Schema::hasColumn('class_reports', 'board_images')) {
                $table->json('board_images')->nullable()->after('board_image');
            }
            if (! Schema::hasColumn('class_reports', 'class_session_id')) {
                $table->foreignId('class_session_id')->nullable()->after('class_id')->constrained('class_sessions')->nullOnDelete();
            }
            if (! Schema::hasColumn('class_reports', 'confirmer_id')) {
                $table->foreignId('confirmer_id')->nullable()->after('reporter_id')->constrained('users')->nullOnDelete();
            }
        });

        // Báo cáo cũ chỉ có 1 ảnh: chép sang danh sách ảnh.
        DB::table('class_reports')->whereNotNull('board_image')->whereNull('board_images')->orderBy('id')
            ->each(function ($row) {
                DB::table('class_reports')->where('id', $row->id)->update(['board_images' => json_encode([$row->board_image])]);
            });
    }

    public function down(): void
    {
        Schema::table('class_reports', function (Blueprint $table) {
            if (Schema::hasColumn('class_reports', 'confirmer_id')) {
                $table->dropConstrainedForeignId('confirmer_id');
            }
            if (Schema::hasColumn('class_reports', 'class_session_id')) {
                $table->dropConstrainedForeignId('class_session_id');
            }
            if (Schema::hasColumn('class_reports', 'board_images')) {
                $table->dropColumn('board_images');
            }
        });
    }
};
