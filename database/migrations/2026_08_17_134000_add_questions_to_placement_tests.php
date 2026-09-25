<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('placement_tests', function (Blueprint $table) {
            if (!Schema::hasColumn('placement_tests', 'questions')) {
                $table->json('questions')->nullable()->after('questions_count');
            }
            if (!Schema::hasColumn('placement_tests', 'description')) {
                $table->text('description')->nullable()->after('title');
            }
        });
    }

    public function down(): void
    {
        Schema::table('placement_tests', function (Blueprint $table) {
            $table->dropColumn(['questions', 'description']);
        });
    }
};
