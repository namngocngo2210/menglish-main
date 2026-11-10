<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cover_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_session_id')->constrained('class_sessions')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('original_teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('approved_user_id')->nullable()->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index('status');
            $table->index('class_session_id');
        });

        Schema::create('cover_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cover_request_id')->constrained('cover_requests')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['cover_request_id', 'teacher_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cover_registrations');
        Schema::dropIfExists('cover_requests');
    }
};
