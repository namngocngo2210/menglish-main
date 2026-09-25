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
        Schema::table('student_tuitions', function (Blueprint $table) {
            if (!Schema::hasColumn('student_tuitions', 'fee_items')) {
                $table->json('fee_items')->nullable()->after('other_fees');
            }
        });

        Schema::table('tuition_receipts', function (Blueprint $table) {
            if (!Schema::hasColumn('tuition_receipts', 'collected_items')) {
                $table->json('collected_items')->nullable()->after('split_details');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_tuitions', function (Blueprint $table) {
            if (Schema::hasColumn('student_tuitions', 'fee_items')) {
                $table->dropColumn('fee_items');
            }
        });

        Schema::table('tuition_receipts', function (Blueprint $table) {
            if (Schema::hasColumn('tuition_receipts', 'collected_items')) {
                $table->dropColumn('collected_items');
            }
        });
    }
};
