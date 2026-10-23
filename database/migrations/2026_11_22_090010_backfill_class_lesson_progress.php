<?php

use App\Services\Syllabus\ProgressBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * Lớp đang chạy lúc triển khai (docs/kho-syllabus.md §11): đánh dấu sẵn Đã dạy các buổi đã học (cách đếm cũ, tối đa số buổi
 * của chặng; buổi vượt quá = buổi ôn) để lớp không quay về buổi 1. Tách riêng khỏi migration tạo bảng; idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(ProgressBackfill::class)->run();
    }

    public function down(): void
    {
        // Không xoá: dữ liệu tiến độ sau backfill có thể đã được GV bổ sung.
    }
};
