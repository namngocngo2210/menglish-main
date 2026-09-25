<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_customers', function (Blueprint $table) {
            $table->string('test_decision', 20)->nullable()->after('stage')->index();
            $table->foreignId('converted_by')->nullable()->after('converted_student_id')->constrained('users')->nullOnDelete();
            $table->foreignId('commission_user_id')->nullable()->after('converted_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_customers', function (Blueprint $table) {
            $table->dropIndex(['test_decision']);
            $table->dropConstrainedForeignId('converted_by');
            $table->dropConstrainedForeignId('commission_user_id');
            $table->dropColumn('test_decision');
        });
    }
};
