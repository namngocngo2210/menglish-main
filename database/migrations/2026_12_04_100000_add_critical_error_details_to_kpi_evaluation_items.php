<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_evaluation_items', function (Blueprint $table) {
            $table->text('critical_note')->nullable();
            $table->foreignId('critical_marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('critical_marked_at')->nullable();
            $table->decimal('critical_prev_score', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('kpi_evaluation_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('critical_marked_by');
            $table->dropColumn(['critical_note', 'critical_marked_at', 'critical_prev_score']);
        });
    }
};
