<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trường còn thiếu khi nhập danh sách lớp từ hệ thống cũ: CCCD / mã định danh, Facebook học viên,
        // email + Facebook phụ huynh, phụ huynh thứ hai (nhiều hồ sơ ghi cả bố lẫn mẹ).
        Schema::table('students', function (Blueprint $table) {
            $table->string('id_card_number', 30)->nullable()->after('school');
            $table->string('facebook')->nullable()->after('email');
            $table->string('parent_email')->nullable()->after('parent_phone');
            $table->string('parent_facebook')->nullable()->after('parent_email');
            $table->string('parent2_name')->nullable()->after('parent_facebook');
            $table->string('parent2_phone', 20)->nullable()->after('parent2_name');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['id_card_number', 'facebook', 'parent_email', 'parent_facebook', 'parent2_name', 'parent2_phone']);
        });
    }
};
