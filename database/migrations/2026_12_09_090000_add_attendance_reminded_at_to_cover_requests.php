<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cover_requests', function (Blueprint $table) {
            $table->timestamp('attendance_reminded_at')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('cover_requests', function (Blueprint $table) {
            $table->dropColumn('attendance_reminded_at');
        });
    }
};
