<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Quy trình kỷ luật (BPMN 9b): ghi nhận vi phạm → nhân viên giải trình →
     * Học thuật (HT) hoặc Học vụ/Quản lý (CM) chốt theo loại lỗi và mức phạt →
     * nộp trong 2 ngày → quá hạn chưa nộp thì trừ vào kỳ lương.
     */
    public function up(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->string('error_category', 30)->nullable()->after('violation_type');
            $table->text('explanation')->nullable()->after('notes');
            $table->timestamp('explained_at')->nullable()->after('explanation');
            $table->foreignId('decided_by')->nullable()->after('explained_at')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('decided_by');
            $table->text('decision_note')->nullable()->after('decided_at');
            $table->date('due_date')->nullable()->after('decision_note');
            $table->timestamp('paid_at')->nullable()->after('due_date');
            $table->index(['status', 'due_date']);
        });

        // HT (academic_lead) và Học vụ (academic_staff) cần xem/lập và chốt biên bản theo loại lỗi.
        $names = ['violation.view', 'violation.create', 'violation.confirm_error', 'violation.confirm_fine'];
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }
        foreach (['academic_lead', 'academic_staff'] as $roleName) {
            Role::where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($names);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->dropIndex(['status', 'due_date']);
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['error_category', 'explanation', 'explained_at', 'decided_at', 'decision_note', 'due_date', 'paid_at']);
        });
    }
};
