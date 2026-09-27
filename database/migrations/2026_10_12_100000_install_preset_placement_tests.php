<?php

use App\Models\PlacementTest;
use Illuminate\Database\Migrations\Migration;

/**
 * Nạp bộ đề test đầu vào mẫu của trung tâm (Khối 1–2 … 4–5, lớp 5–9, Speaking) cho môi trường thật.
 * Trước đây bộ đề chỉ có trong seed demo nên hosting không có đề nào và ô "Chọn cấp độ" ở CRM trống.
 * Chỉ thêm mã đề chưa có (kể cả đề đã xóa mềm thì bỏ qua) — không ghi đè đề trung tâm đã sửa.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Test tự tạo đề theo mã riêng; cài bộ mẫu ở đây sẽ trùng mã.
        if (app()->runningUnitTests()) {
            return;
        }

        PlacementTest::installMissingPresets();
    }

    public function down(): void
    {
        // Không xóa: đề có thể đã được dùng cho bài làm của khách.
    }
};
