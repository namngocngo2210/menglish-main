<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_customers', function (Blueprint $table) {
            $table->dateTime('trial_at')->nullable()->after('appointment_type');
            $table->foreignId('trial_teacher_id')->nullable()->after('trial_at')->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('trial_rating')->nullable()->after('trial_teacher_id');
            $table->text('trial_feedback')->nullable()->after('trial_rating');
            $table->date('waiting_since')->nullable()->after('trial_feedback');
            $table->string('preferred_schedule')->nullable()->after('waiting_since');
        });
    }

    public function down(): void
    {
        Schema::table('crm_customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trial_teacher_id');
            $table->dropColumn(['trial_at', 'trial_rating', 'trial_feedback', 'waiting_since', 'preferred_schedule']);
        });
    }
};
