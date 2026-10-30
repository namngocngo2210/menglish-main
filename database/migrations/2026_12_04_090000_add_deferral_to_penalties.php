<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->date('deduct_from_date')->nullable()->after('violation_date');
            $table->timestamp('deferred_at')->nullable();
            $table->foreignId('deferred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('defer_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->dropForeign(['deferred_by']);
            $table->dropColumn(['deduct_from_date', 'deferred_at', 'deferred_by', 'defer_reason']);
        });
    }
};
