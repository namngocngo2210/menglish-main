<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Một tài khoản ngân hàng gắn được với nhiều cơ sở (trước đây chỉ một cột branch_id).
     * Tài khoản không gắn cơ sở nào là tài khoản dùng chung cho mọi cơ sở.
     * Chuyển dữ liệu branch_id cũ sang bảng nối rồi bỏ cột.
     */
    public function up(): void
    {
        Schema::create('bank_account_branch', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['bank_account_id', 'branch_id']);
            $table->index('branch_id');
        });

        $now = now();
        DB::table('bank_accounts')->whereNotNull('branch_id')->orderBy('id')
            ->get(['id', 'branch_id'])
            ->each(fn ($row) => DB::table('bank_account_branch')->insert([
                'bank_account_id' => $row->id,
                'branch_id' => $row->branch_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('branch_location')->constrained('branches')->nullOnDelete();
        });

        // Cột cũ chỉ giữ được một cơ sở: lấy cơ sở gắn sớm nhất của mỗi tài khoản.
        DB::table('bank_account_branch')->orderBy('id')->get(['bank_account_id', 'branch_id'])
            ->unique('bank_account_id')
            ->each(fn ($row) => DB::table('bank_accounts')->where('id', $row->bank_account_id)->update(['branch_id' => $row->branch_id]));

        Schema::dropIfExists('bank_account_branch');
    }
};
