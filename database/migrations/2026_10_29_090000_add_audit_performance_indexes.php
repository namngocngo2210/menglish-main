<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đợt rà soát DB 2026-10-04: index cho truy vấn nóng còn thiếu (xem docs trong PR). Tên index đặt tường minh (≤ 64 ký tự,
 * MySQL hosting). Chạy lại an toàn: bỏ qua bảng / cột / index không có hoặc đã có.
 *
 * Index ghép mở đầu bằng cột khoá ngoại: MySQL có thể tự bỏ index khoá ngoại ngầm định và dùng index ghép thay thế, khi
 * rollback không xoá được index ghép ("needed in a foreign key constraint"). Vì vậy down() dựng lại index đơn của cột
 * đầu trước khi xoá.
 */
return new class extends Migration
{
    /** @var array<string, array<string, list<string>>> bảng => [tên index => cột] */
    private const INDEXES = [
        // AuditOperationMiddleware tra batch_uuid ở mọi request ghi (POST/PUT/PATCH/DELETE).
        'activity_log' => ['activity_log_batch_uuid_index' => ['batch_uuid']],
        // Chuông thông báo: 6 thông báo mới nhất của một người, sắp theo thời gian.
        'admin_notifications' => ['admin_notifications_user_created_idx' => ['user_id', 'created_at']],
        // Tính lương / chấm công: buổi dạy của một giáo viên hoặc một lớp trong khoảng ngày.
        'teacher_timesheets' => [
            'teacher_timesheets_user_date_status_idx' => ['user_id', 'teaching_date', 'status'],
            'teacher_timesheets_class_date_idx' => ['class_id', 'teaching_date'],
        ],
        // Nhắc nợ / công nợ quá hạn lọc theo hạn đóng.
        'student_tuitions' => ['student_tuitions_due_date_index' => ['due_date']],
        // Khớp chuyển khoản SePay theo số điện thoại; cổng học viên khớp theo email.
        'students' => [
            'students_phone_index' => ['phone'],
            'students_email_index' => ['email'],
        ],
        // Bảng việc của tôi: việc của một người theo trạng thái và hạn.
        'work_tasks' => ['work_tasks_assignee_status_due_idx' => ['assignee_id', 'status', 'due_date']],
        // Điểm danh: báo cáo theo ngày học (index hiện có đều mở đầu bằng class_id).
        'student_attendances' => ['student_attendances_session_date_index' => ['session_date']],
        // Sĩ số lớp: đếm học viên theo trạng thái ghi danh của một lớp.
        'class_enrollments' => ['class_enrollments_class_status_idx' => ['class_id', 'status']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (Schema::hasTable($table) && Schema::hasColumns($table, $columns) && ! Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $name)) {
                    continue;
                }
                if (count($columns) > 1 && ! Schema::hasIndex($table, [$columns[0]])) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns[0], "{$table}_{$columns[0]}_index"));
                }
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }
};
