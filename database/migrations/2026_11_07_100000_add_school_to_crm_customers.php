<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ô "Trường học" của khách hàng CRM (chép sang hồ sơ học viên khi chốt — students.school).
        Schema::table('crm_customers', function (Blueprint $table) {
            $table->string('school', 255)->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('crm_customers', function (Blueprint $table) {
            $table->dropColumn('school');
        });
    }
};
