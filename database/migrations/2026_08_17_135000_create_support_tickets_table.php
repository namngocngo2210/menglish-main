<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->string('category')->default('technical_issue'); // technical_issue, curriculum, tuition, customer_complaint, other
            $table->string('priority')->default('medium'); // low, medium, high, urgent
            $table->string('status')->default('open'); // open, in_progress, resolved, closed
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description');
            $table->string('attachment_path')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('message');
            $table->string('attachment_path')->nullable();
            $table->boolean('is_internal_note')->default(false);
            $table->timestamps();
        });

        Schema::table('crm_customers', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_customers', 'appointment_at')) {
                $table->dateTime('appointment_at')->nullable()->after('test_score');
            }
            if (!Schema::hasColumn('crm_customers', 'appointment_type')) {
                $table->string('appointment_type')->nullable()->after('appointment_at'); // online, offline
            }
            if (!Schema::hasColumn('crm_customers', 'assigned_test_id')) {
                $table->unsignedBigInteger('assigned_test_id')->nullable()->after('appointment_type');
            }
            if (!Schema::hasColumn('crm_customers', 'examiner_id')) {
                $table->unsignedBigInteger('examiner_id')->nullable()->after('assigned_test_id');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('support_tickets');
        Schema::table('crm_customers', function (Blueprint $table) {
            $table->dropColumn(['appointment_at', 'appointment_type', 'assigned_test_id', 'examiner_id']);
        });
    }
};
