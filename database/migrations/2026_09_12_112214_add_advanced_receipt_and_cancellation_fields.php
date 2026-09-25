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
        Schema::table('tuition_receipts', function (Blueprint $table) {
            if (!Schema::hasColumn('tuition_receipts', 'student_id')) {
                $table->foreignId('student_id')->nullable()->after('student_tuition_id')->constrained('students')->nullOnDelete();
            }
            if (!Schema::hasColumn('tuition_receipts', 'tuition_amount')) {
                $table->decimal('tuition_amount', 15, 2)->default(0)->after('amount');
            }
            if (!Schema::hasColumn('tuition_receipts', 'surcharge_amount')) {
                $table->decimal('surcharge_amount', 15, 2)->default(0)->after('tuition_amount');
            }
            if (!Schema::hasColumn('tuition_receipts', 'surcharge_reason')) {
                $table->string('surcharge_reason', 500)->nullable()->after('surcharge_amount');
            }
            if (!Schema::hasColumn('tuition_receipts', 'discount_amount')) {
                $table->decimal('discount_amount', 15, 2)->default(0)->after('surcharge_reason');
            }
            if (!Schema::hasColumn('tuition_receipts', 'payer_name')) {
                $table->string('payer_name', 255)->nullable()->after('transaction_code');
            }
            if (!Schema::hasColumn('tuition_receipts', 'payer_phone')) {
                $table->string('payer_phone', 50)->nullable()->after('payer_name');
            }
            if (!Schema::hasColumn('tuition_receipts', 'is_vat_invoice')) {
                $table->boolean('is_vat_invoice')->default(false)->after('payer_phone');
            }
            if (!Schema::hasColumn('tuition_receipts', 'paper_invoice_number')) {
                $table->string('paper_invoice_number', 100)->nullable()->after('is_vat_invoice');
            }
            if (!Schema::hasColumn('tuition_receipts', 'proof_image')) {
                $table->string('proof_image', 1000)->nullable()->after('paper_invoice_number');
            }
            if (!Schema::hasColumn('tuition_receipts', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('notes');
            }
        });

        // Make student_tuition_id nullable if supported
        try {
            Schema::table('tuition_receipts', function (Blueprint $table) {
                $table->unsignedBigInteger('student_tuition_id')->nullable()->change();
            });
        } catch (\Throwable $e) {
            // Ignore if driver doesn't support modify column
        }

        Schema::table('invoice_cancellations', function (Blueprint $table) {
            if (!Schema::hasColumn('invoice_cancellations', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('reason');
            }
            if (!Schema::hasColumn('invoice_cancellations', 'proof_image')) {
                $table->string('proof_image', 1000)->nullable()->after('rejection_reason');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tuition_receipts', function (Blueprint $table) {
            if (Schema::hasColumn('tuition_receipts', 'student_id')) {
                $table->dropForeign(['student_id']);
                $table->dropColumn('student_id');
            }
            $columns = [
                'tuition_amount',
                'surcharge_amount',
                'surcharge_reason',
                'discount_amount',
                'payer_name',
                'payer_phone',
                'is_vat_invoice',
                'paper_invoice_number',
                'proof_image',
                'rejection_reason',
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('tuition_receipts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('invoice_cancellations', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_cancellations', 'rejection_reason')) {
                $table->dropColumn('rejection_reason');
            }
            if (Schema::hasColumn('invoice_cancellations', 'proof_image')) {
                $table->dropColumn('proof_image');
            }
        });
    }
};
