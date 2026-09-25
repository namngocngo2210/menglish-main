<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Revoke the credential that was previously committed as a default.
        DB::table('sepay_configurations')->select(['id', 'secret_key'])->orderBy('id')->each(function ($config): void {
            $compromisedSecretHash = 'eb3e8ce4c1e1887303d74bbaefc6b54580a2ebfdb11eefabcae7d602f9711cae';
            if ($config->secret_key && hash_equals($compromisedSecretHash, hash('sha256', $config->secret_key))) {
                DB::table('sepay_configurations')->where('id', $config->id)->update([
                    'secret_key' => Str::random(64),
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
            }
        });

        // Preserve historic duplicate rows, but detach their external id so a
        // unique index can enforce idempotency for all future callbacks.
        DB::table('sepay_transactions')
            ->whereNotNull('sepay_id')
            ->select('sepay_id')
            ->groupBy('sepay_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('sepay_id')
            ->each(function (string $sepayId): void {
                $keepId = DB::table('sepay_transactions')
                    ->where('sepay_id', $sepayId)
                    ->min('id');

                DB::table('sepay_transactions')
                    ->where('sepay_id', $sepayId)
                    ->where('id', '!=', $keepId)
                    ->update(['sepay_id' => null]);
            });

        Schema::table('sepay_transactions', function (Blueprint $table) {
            $table->unique('sepay_id', 'sepay_transactions_sepay_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sepay_transactions', function (Blueprint $table) {
            $table->dropUnique('sepay_transactions_sepay_id_unique');
        });
    }
};
