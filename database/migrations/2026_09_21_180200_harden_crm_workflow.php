<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_customers', function (Blueprint $table) {
            $table->string('phone_normalized', 30)->nullable()->after('phone');
            $table->foreignId('converted_student_id')->nullable()->after('lost_reason')->unique()->constrained('students')->nullOnDelete();
            $table->timestamp('converted_at')->nullable()->after('converted_student_id');
        });

        $seen = [];
        DB::table('crm_customers')->orderBy('id')->get(['id', 'phone'])->each(function ($customer) use (&$seen): void {
            $normalized = preg_replace('/\D+/', '', (string) $customer->phone);
            if ($normalized === '' || isset($seen[$normalized])) {
                return;
            }

            $seen[$normalized] = true;
            DB::table('crm_customers')->where('id', $customer->id)->update(['phone_normalized' => $normalized]);
        });

        Schema::table('crm_customers', function (Blueprint $table) {
            $table->unique('phone_normalized');
        });

        Schema::table('student_tuitions', function (Blueprint $table) {
            $table->foreignId('bank_account_id')->nullable()->after('branch_id')->constrained('bank_accounts')->nullOnDelete();
        });

    }

    public function down(): void
    {
        Schema::table('student_tuitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_id');
        });

        Schema::table('crm_customers', function (Blueprint $table) {
            $table->dropUnique(['phone_normalized']);
            $table->dropConstrainedForeignId('converted_student_id');
            $table->dropColumn(['phone_normalized', 'converted_at']);
        });
    }
};
