<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rà soát hiệu năng truy vấn: index cho các cột lọc / sắp xếp thường xuyên trên bảng lớn dần theo thời gian.
 * Không đặt cột khoá ngoại ở đầu index ghép (MySQL có thể dùng index đó thay index khoá ngoại, khi rollback sẽ không
 * xoá được). Chạy lại an toàn (bỏ qua index đã có).
 */
return new class extends Migration
{
    /** @var array<string, array<string, list<string>>> bảng => [tên index => cột] */
    private const INDEXES = [
        // Nhật ký hoạt động: danh sách sắp theo thời gian mới nhất, bảng tăng theo mọi thao tác.
        'activity_log' => ['activity_log_created_at_index' => ['created_at']],
        // Chuông thông báo trên mọi trang (đếm chưa đọc) + quét khách bị bỏ quên theo loại thông báo.
        'admin_notifications' => [
            'admin_notifications_is_read_user_id_index' => ['is_read', 'user_id'],
            'admin_notifications_type_index' => ['type'],
        ],
        // Doanh thu theo kỳ (Tổng quan, báo cáo tài chính, đối soát SePay): phiếu đã duyệt trong khoảng ngày thu.
        'tuition_receipts' => ['tuition_receipts_status_payment_date_index' => ['status', 'payment_date']],
        // Chấm công / bảng lương lọc theo khoảng ngày dạy của mọi giáo viên.
        'teacher_timesheets' => ['teacher_timesheets_teaching_date_index' => ['teaching_date']],
        // CRM: đếm / lọc theo giai đoạn khi xem toàn hệ thống, báo cáo theo ngày tạo.
        'crm_customers' => ['crm_customers_stage_created_at_index' => ['stage', 'created_at']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (Schema::hasTable($table) && ! Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
