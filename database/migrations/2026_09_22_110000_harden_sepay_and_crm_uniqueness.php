<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * crm_customers.email UNIQUE (trước đây chỉ kiểm tra ở tầng PHP, bị race TOCTOU).
     * sepay_transactions.sepay_id đã có UNIQUE từ migration 2026_09_21_180000.
     */
    public function up(): void
    {
        // Chuẩn hoá dữ liệu trùng trước khi đánh UNIQUE: giữ email của dòng cũ nhất, các dòng sau bỏ trống.
        $duplicateEmails = DB::table('crm_customers')
            ->whereNotNull('email')
            ->select('email')
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('email');

        foreach ($duplicateEmails as $email) {
            $keepId = DB::table('crm_customers')->where('email', $email)->orderBy('id')->value('id');
            DB::table('crm_customers')
                ->where('email', $email)
                ->where('id', '!=', $keepId)
                ->update(['email' => null]);
        }

        $emailAlreadyUnique = collect(Schema::getIndexes('crm_customers'))
            ->contains(fn (array $index) => $index['columns'] === ['email'] && $index['unique']);

        if (! $emailAlreadyUnique) {
            Schema::table('crm_customers', function (Blueprint $table) {
                $table->unique('email');
            });
        }
    }

    public function down(): void
    {
        Schema::table('crm_customers', function (Blueprint $table) {
            $table->dropUnique(['email']);
        });
    }
};
