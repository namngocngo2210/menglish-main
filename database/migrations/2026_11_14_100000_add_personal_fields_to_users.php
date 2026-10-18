<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trường còn thiếu khi nhập hồ sơ giáo viên / trợ giảng từ sheet "Thông tin nhân sự": ngày sinh, email cá nhân
        // (email đăng nhập là email công việc), ngày cấp CCCD, Facebook, chuyên ngành, ngày bắt đầu làm việc, TK ngân hàng
        // nhận lương, kênh liên lạc với quản lý nhân sự, ghi chú.
        Schema::table('users', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('phone');
            $table->string('personal_email')->nullable()->after('email');
            $table->date('id_card_issued_on')->nullable()->after('id_card_number');
            $table->string('facebook')->nullable()->after('personal_email');
            $table->string('major')->nullable()->after('graduation_school');
            $table->date('joined_on')->nullable()->after('contract_type');
            $table->string('bank_account')->nullable()->after('joined_on');
            $table->string('contact_channel', 50)->nullable()->after('bank_account');
            $table->text('staff_note')->nullable()->after('contact_channel');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['date_of_birth', 'personal_email', 'id_card_issued_on', 'facebook', 'major', 'joined_on', 'bank_account', 'contact_channel', 'staff_note']);
        });
    }
};
