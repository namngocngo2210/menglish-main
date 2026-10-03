<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bậc phạt theo lần tái phạm Admin chỉnh (mảng số tiền, phần tử cuối lặp lại); null = mặc định trong config/sla.php.
        Schema::table('sla_settings', fn (Blueprint $table) => $table->json('ladder')->nullable()->after('amount'));
    }

    public function down(): void
    {
        Schema::table('sla_settings', fn (Blueprint $table) => $table->dropColumn('ladder'));
    }
};
