<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // Giá trị Admin đổi cho từng SLA (config/sla.php giữ mặc định); null = dùng mặc định.
        Schema::create('sla_settings', function (Blueprint $table) {
            $table->id();
            $table->string('rule_key')->unique();
            $table->unsignedInteger('value')->nullable();
            $table->boolean('enabled')->nullable();
            $table->boolean('penalty')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Sổ SLA: mỗi (SLA, đối tượng) một dòng — đảm bảo idempotent (không phạt / giao việc / báo hai lần).
        Schema::create('sla_events', function (Blueprint $table) {
            $table->id();
            $table->string('rule_key');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('triggered_at');
            $table->dateTime('due_at');
            $table->dateTime('breached_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('penalty_id')->nullable()->constrained('penalties')->nullOnDelete();
            $table->foreignId('work_task_id')->nullable()->constrained('work_tasks')->nullOnDelete();
            $table->timestamps();

            $table->unique(['rule_key', 'subject_type', 'subject_id']);
            $table->index(['rule_key', 'breached_at']);
        });

        // Cột kết quả liên hệ (reached | failed) cho nhật ký gọi / nhắn / gặp; liên hệ thất bại không tính là đã liên hệ.
        Schema::table('crm_customer_histories', function (Blueprint $table) {
            $table->string('outcome', 16)->nullable()->after('type');
        });

        Permission::findOrCreate('sla.configure', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('crm_customer_histories', fn (Blueprint $table) => $table->dropColumn('outcome'));
        Schema::dropIfExists('sla_events');
        Schema::dropIfExists('sla_settings');
        Permission::query()->where('name', 'sla.configure')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
