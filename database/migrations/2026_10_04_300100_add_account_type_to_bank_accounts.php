<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mockup cau-hinh-tai-khoan-ngan-hang: "Loại tài khoản" — Công ty (chủ sở hữu chính) / Khác (cá nhân / đại diện).
     * Tài khoản cũ mặc định là tài khoản công ty.
     */
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->string('account_type', 20)->default('company')->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn('account_type');
        });
    }
};
