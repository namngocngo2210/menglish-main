<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'base_salary')) {
                $table->decimal('base_salary', 15, 2)->default(0)->after('branch_id');
            }
            if (!Schema::hasColumn('users', 'hourly_rate')) {
                $table->decimal('hourly_rate', 15, 2)->default(0)->after('base_salary');
            }
            if (!Schema::hasColumn('users', 'department')) {
                $table->string('department')->default('fulltime')->after('hourly_rate');
            }
            if (!Schema::hasColumn('users', 'teacher_rate_id')) {
                $table->unsignedBigInteger('teacher_rate_id')->nullable()->after('department');
            }
        });

        Schema::table('tuition_receipts', function (Blueprint $table) {
            if (!Schema::hasColumn('tuition_receipts', 'invoice_number')) {
                $table->string('invoice_number')->nullable()->after('receipt_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['base_salary', 'hourly_rate', 'department', 'teacher_rate_id']);
        });

        Schema::table('tuition_receipts', function (Blueprint $table) {
            $table->dropColumn(['invoice_number']);
        });
    }
};
