<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_retention_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('min_seniority_months')->default(0)->index();
            $table->unsignedSmallInteger('from_students');
            $table->unsignedSmallInteger('to_students')->nullable();
            $table->decimal('rate', 12, 2);
            $table->timestamps();
        });

        DB::table('kpi_retention_tiers')->insert([
            ['min_seniority_months' => 0, 'from_students' => 1, 'to_students' => 5, 'rate' => 25000, 'created_at' => now(), 'updated_at' => now()],
            ['min_seniority_months' => 0, 'from_students' => 6, 'to_students' => 10, 'rate' => 30000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::table('payroll_records', function (Blueprint $table) {
            $table->timestamp('kpi_finalized_at')->nullable();
            $table->unsignedBigInteger('kpi_finalized_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->dropColumn(['kpi_finalized_at', 'kpi_finalized_by']);
        });
        Schema::dropIfExists('kpi_retention_tiers');
    }
};
