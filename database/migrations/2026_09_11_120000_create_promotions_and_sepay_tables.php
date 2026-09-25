<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Bảng Chương trình Ưu đãi & Voucher
        if (! Schema::hasTable('promotions')) {
            Schema::create('promotions', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->enum('type', ['fixed', 'percent'])->default('fixed');
                $table->decimal('value', 15, 2)->default(0);
                $table->decimal('max_discount_amount', 15, 2)->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // 2. Bảng Cấu hình Webhook SePay Gateway
        if (! Schema::hasTable('sepay_configurations')) {
            Schema::create('sepay_configurations', function (Blueprint $table) {
                $table->id();
                $table->string('webhook_name')->default('Xác Thực Thanh Toán Meducation');
                $table->string('webhook_url')->default('https://dungthu.meducation.vn/hook/sepay-gateway/v1/add-payment');
                $table->string('transaction_type')->default('in'); // in, out, all
                $table->string('data_format')->default('json'); // json, form, urlencoded
                $table->string('auth_method')->default('hmac_sha256'); // hmac_sha256, api_key, none
                $table->string('api_key')->nullable();
                $table->string('secret_key')->nullable();
                $table->boolean('is_active')->default(false);
                $table->boolean('auto_retry')->default(true);
                $table->timestamps();
            });
        }

        // 3. Bảng Lịch sử Giao dịch Webhook SePay (Đối soát & Tự động gạch nợ)
        if (! Schema::hasTable('sepay_transactions')) {
            Schema::create('sepay_transactions', function (Blueprint $table) {
                $table->id();
                $table->string('sepay_id')->nullable()->index();
                $table->string('gateway')->nullable();
                $table->dateTime('transaction_date')->nullable();
                $table->string('account_number')->nullable();
                $table->string('sub_account')->nullable();
                $table->string('transfer_type')->default('in');
                $table->decimal('transfer_amount', 15, 2)->default(0);
                $table->decimal('accumulated', 15, 2)->default(0);
                $table->text('content')->nullable();
                $table->string('reference_code')->nullable();
                $table->json('raw_payload')->nullable();
                $table->string('status')->default('pending'); // matched, unmatched, duplicate, error
                $table->unsignedBigInteger('matched_student_id')->nullable()->index();
                $table->unsignedBigInteger('matched_tuition_id')->nullable()->index();
                $table->unsignedBigInteger('matched_receipt_id')->nullable()->index();
                $table->text('response_message')->nullable();
                $table->timestamps();
            });
        }

        // 4. Bổ sung các trường tài chính mở rộng cho StudentTuition
        Schema::table('student_tuitions', function (Blueprint $table) {
            if (! Schema::hasColumn('student_tuitions', 'other_fees')) {
                $table->decimal('other_fees', 15, 2)->default(0)->after('discount_amount');
            }
            if (! Schema::hasColumn('student_tuitions', 'prepaid_amount')) {
                $table->decimal('prepaid_amount', 15, 2)->default(0)->after('other_fees');
            }
            if (! Schema::hasColumn('student_tuitions', 'promotion_id')) {
                $table->unsignedBigInteger('promotion_id')->nullable()->after('branch_id');
            }
            if (! Schema::hasColumn('student_tuitions', 'transfer_memo')) {
                $table->string('transfer_memo')->nullable()->after('notes');
            }
        });

        // 5. Bổ sung trường chi tiết kết hợp nhiều hình thức thanh toán cho TuitionReceipt
        Schema::table('tuition_receipts', function (Blueprint $table) {
            if (! Schema::hasColumn('tuition_receipts', 'split_details')) {
                $table->json('split_details')->nullable()->after('payment_method');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tuition_receipts', function (Blueprint $table) {
            if (Schema::hasColumn('tuition_receipts', 'split_details')) {
                $table->dropColumn('split_details');
            }
        });

        Schema::table('student_tuitions', function (Blueprint $table) {
            $cols = array_filter(['other_fees', 'prepaid_amount', 'promotion_id', 'transfer_memo'], fn ($c) => Schema::hasColumn('student_tuitions', $c));
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        Schema::dropIfExists('sepay_transactions');
        Schema::dropIfExists('sepay_configurations');
        Schema::dropIfExists('promotions');
    }
};
