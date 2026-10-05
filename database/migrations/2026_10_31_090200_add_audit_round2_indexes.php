<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit lần 2: index cho các cột lọc / sắp xếp thường xuyên mà chưa có index.
 * Tên index đặt tay (≤ 64 ký tự cho MySQL), bỏ qua nếu đã có.
 */
return new class extends Migration
{
    private const INDEXES = [
        'crm_customers' => [
            'crm_customers_created_at_idx' => ['created_at'],
            'crm_customers_appointment_at_idx' => ['appointment_at'],
            'crm_customers_examiner_id_idx' => ['examiner_id'],
        ],
        'activity_log' => [
            'activity_log_event_idx' => ['event'],
        ],
        'student_tuitions' => [
            'student_tuitions_status_due_idx' => ['status', 'due_date'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($indexes as $name => $columns) {
                if (Schema::hasIndex($table, $name) || ! Schema::hasColumns($table, $columns)) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
