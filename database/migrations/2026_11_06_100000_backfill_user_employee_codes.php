<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Đăng nhập bằng mã nhân viên (12/10/2026): mọi tài khoản cần mã thật trong DB. Tài khoản chưa có mã (vd. Admin khởi tạo
 * từ INITIAL_ADMIN_EMAIL, nhân sự tạo không nhập mã) nhận mã MES kế tiếp theo thứ tự tạo tài khoản (MES001, MES002, …).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where(fn ($q) => $q->whereNull('employee_code')->orWhere('employee_code', ''))
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $id) => DB::table('users')->where('id', $id)->update(['employee_code' => User::nextEmployeeCode()]));
    }

    public function down(): void
    {
        // Không hoàn tác: mã đã cấp có thể đang được dùng để đăng nhập.
    }
};
