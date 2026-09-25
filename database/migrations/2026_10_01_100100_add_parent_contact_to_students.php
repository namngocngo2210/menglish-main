<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Liên hệ phụ huynh trên hồ sơ học viên (gửi kết quả Big Test qua Zalo cho phụ huynh).
     * Backfill từ khách CRM đã chốt ra học viên (crm_customers.converted_student_id), bản ghi khách mới nhất.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('parent_name')->nullable()->after('phone');
            $table->string('parent_phone', 20)->nullable()->after('parent_name');
        });

        DB::table('crm_customers')
            ->whereNotNull('converted_student_id')
            ->where(fn ($q) => $q->whereNotNull('parent_phone')->orWhereNotNull('parent_name'))
            ->orderBy('id')
            ->get(['converted_student_id', 'parent_name', 'parent_phone'])
            ->keyBy('converted_student_id') // khách chốt sau (id lớn hơn) ghi đè
            ->each(function ($customer, $studentId) {
                $phone = trim((string) $customer->parent_phone);
                $name = trim((string) $customer->parent_name);
                $values = array_filter([
                    'parent_phone' => $phone !== '' ? mb_substr($phone, 0, 20) : null,
                    'parent_name' => $name !== '' ? $name : null,
                ]);
                if ($values !== []) {
                    DB::table('students')->where('id', $studentId)->update($values);
                }
            });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['parent_name', 'parent_phone']);
        });
    }
};
