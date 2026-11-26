<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->unsignedInteger('opening_sessions')->default(0);
            $table->timestamp('opening_sessions_set_at')->nullable();
            $table->date('summer_break_from')->nullable();
            $table->date('summer_break_to')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['opening_sessions', 'opening_sessions_set_at', 'summer_break_from', 'summer_break_to']);
        });
    }
};
