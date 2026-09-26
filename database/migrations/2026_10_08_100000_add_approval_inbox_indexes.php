<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IX-5 "Việc cần duyệt": badge + danh sách chờ duyệt lọc theo trạng thái ở các bảng chưa có index trạng thái
 * (tuition_receipts, syllabus_change_proposals, big_test_orders đã có). Chạy lại an toàn (bỏ qua index đã có).
 */
return new class extends Migration
{
    /** @var array<string, array<string, list<string>>> bảng => [tên index => cột] */
    private const INDEXES = [
        'invoice_cancellations' => ['invoice_cancellations_status_created_at_index' => ['status', 'created_at']],
        'tuition_refund_requests' => ['tuition_refund_requests_status_type_index' => ['status', 'type']],
        'class_enrollments' => ['class_enrollments_status_confirmed_at_index' => ['status', 'confirmed_at']],
        'syllabus_adjustment_requests' => ['syllabus_adjustment_requests_status_created_at_index' => ['status', 'created_at']],
        'work_tasks' => ['work_tasks_status_creator_id_index' => ['status', 'creator_id']],
        'class_reports' => ['class_reports_status_index' => ['status']],
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
