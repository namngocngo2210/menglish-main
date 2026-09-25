<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('placement_test_submissions', function (Blueprint $table) {
            $table->string('cefr_level', 255)->default('B1')->change();
        });
    }

    public function down(): void
    {
        Schema::table('placement_test_submissions', function (Blueprint $table) {
            $table->string('cefr_level', 10)->default('B1')->change();
        });
    }
};
