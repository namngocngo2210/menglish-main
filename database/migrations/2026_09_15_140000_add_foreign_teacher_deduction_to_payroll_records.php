<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->integer('foreign_teacher_sessions_count')->default(0)->after('penalty_deduction');
            $table->decimal('foreign_teacher_deduction_rate', 15, 2)->default(50000)->after('foreign_teacher_sessions_count');
            $table->decimal('foreign_teacher_deduction', 15, 2)->default(0)->after('foreign_teacher_deduction_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->dropColumn([
                'foreign_teacher_sessions_count',
                'foreign_teacher_deduction_rate',
                'foreign_teacher_deduction',
            ]);
        });
    }
};
