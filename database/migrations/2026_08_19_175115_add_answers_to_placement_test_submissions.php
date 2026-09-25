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
        Schema::table('placement_test_submissions', function (Blueprint $table) {
            if (!Schema::hasColumn('placement_test_submissions', 'answers')) {
                $table->json('answers')->nullable()->after('writing_content');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('placement_test_submissions', function (Blueprint $table) {
            if (Schema::hasColumn('placement_test_submissions', 'answers')) {
                $table->dropColumn('answers');
            }
        });
    }
};
