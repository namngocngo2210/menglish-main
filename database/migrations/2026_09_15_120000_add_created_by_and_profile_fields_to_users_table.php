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
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->string('id_card_number', 30)->nullable()->after('phone');
            $table->string('hometown')->nullable()->after('id_card_number');
            $table->string('current_address')->nullable()->after('hometown');
            $table->string('emergency_contact')->nullable()->after('current_address');
            $table->string('graduation_school')->nullable()->after('emergency_contact');
            $table->string('certificates')->nullable()->after('graduation_school');
            $table->string('teaching_level')->nullable()->after('certificates');
            $table->string('contract_type', 50)->nullable()->after('teaching_level');
            $table->date('contract_start_date')->nullable()->after('contract_type');
            $table->date('contract_end_date')->nullable()->after('contract_start_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn([
                'created_by',
                'id_card_number',
                'hometown',
                'current_address',
                'emergency_contact',
                'graduation_school',
                'certificates',
                'teaching_level',
                'contract_type',
                'contract_start_date',
                'contract_end_date',
            ]);
        });
    }
};
