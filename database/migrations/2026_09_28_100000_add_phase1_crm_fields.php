<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 CRM (audit A3/B4):
 * - crm_customers: SĐT phụ huynh, hạn liên hệ tiếp theo, checklist chăm sóc tháng đầu, email lưu tạm khi xóa.
 * - Khách đã xóa (soft delete) không còn giữ chỗ trong UNIQUE(phone_normalized) / UNIQUE(email):
 *   khi xóa, phone_normalized = null và email chuyển sang deleted_email; khi khôi phục thì tính lại.
 * - SĐT chuẩn hoá về dạng 0xxxxxxxxx (+84 / 84 → 0).
 * - crm_customer_histories.changes: dữ liệu trước / sau khi sửa thông tin khách.
 * - class_enrollments: bước "Xác nhận chính thức" (đã gửi tài khoản, người / thời điểm xác nhận).
 * - classes.min_students: ngưỡng khai giảng (mặc định 6 học viên).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_customers', function (Blueprint $table) {
            $table->string('parent_phone', 20)->nullable()->after('parent_name');
            $table->dateTime('next_follow_up_at')->nullable()->after('appointment_type');
            $table->json('care_checklist')->nullable()->after('notes');
            $table->string('deleted_email')->nullable()->after('email');
            $table->index('next_follow_up_at');
        });

        // Khách đã xóa: nhả SĐT / email để tạo lại được khách mới cùng SĐT.
        DB::table('crm_customers')->whereNotNull('deleted_at')->orderBy('id')->get()->each(function ($row): void {
            DB::table('crm_customers')->where('id', $row->id)->update([
                'phone_normalized' => null,
                'deleted_email' => $row->email,
                'email' => null,
            ]);
        });

        // Chuẩn hoá 84xxxxxxxxx → 0xxxxxxxxx (bỏ qua nếu đụng khách khác đã có dạng 0...).
        DB::table('crm_customers')->whereNull('deleted_at')->where('phone_normalized', 'like', '84%')->orderBy('id')->get()
            ->each(function ($row): void {
                if (strlen((string) $row->phone_normalized) !== 11) {
                    return;
                }
                $normalized = '0'.substr($row->phone_normalized, 2);
                if (! DB::table('crm_customers')->where('phone_normalized', $normalized)->exists()) {
                    DB::table('crm_customers')->where('id', $row->id)->update(['phone_normalized' => $normalized]);
                }
            });

        Schema::table('crm_customer_histories', function (Blueprint $table) {
            $table->json('changes')->nullable()->after('reason');
        });

        Schema::table('class_enrollments', function (Blueprint $table) {
            $table->boolean('account_sent')->default(false)->after('zalo_group_added');
            $table->timestamp('confirmed_at')->nullable()->after('status');
            $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_students')->default(6)->after('max_capacity');
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn('min_students');
        });

        Schema::table('class_enrollments', function (Blueprint $table) {
            $table->dropForeign(['confirmed_by']);
            $table->dropColumn(['account_sent', 'confirmed_at', 'confirmed_by']);
        });

        Schema::table('crm_customer_histories', function (Blueprint $table) {
            $table->dropColumn('changes');
        });

        Schema::table('crm_customers', function (Blueprint $table) {
            $table->dropIndex(['next_follow_up_at']);
            $table->dropColumn(['parent_phone', 'next_follow_up_at', 'care_checklist', 'deleted_email']);
        });
    }
};
