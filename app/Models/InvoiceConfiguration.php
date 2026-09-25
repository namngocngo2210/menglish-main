<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class InvoiceConfiguration extends Model
{
    use HasFactory;

    protected $table = 'invoice_configurations';

    protected $fillable = [
        'template_code',
        'series_code',
        'start_number',
        'current_number',
        'provider',
        'auto_issue',
    ];

    protected $casts = [
        'start_number' => 'integer',
        'current_number' => 'integer',
        'auto_issue' => 'boolean',
    ];

    /**
     * Tiêu thụ 1 số hóa đơn điện tử trong dải, tăng current_number và trả về mã hóa đơn chuẩn.
     */
    public static function consumeNextInvoiceNumber(): string
    {
        $config = static::firstOrCreate([], [
            'template_code' => '1/001',
            'series_code' => 'C26MEN',
            'start_number' => 1,
            'current_number' => 1001,
            'provider' => 'vnpt',
            'auto_issue' => true,
        ]);

        return DB::transaction(function () use ($config): string {
            $lockedConfig = static::query()->lockForUpdate()->findOrFail($config->id);
            $number = $lockedConfig->current_number;
            $formattedInvoice = $lockedConfig->series_code.'-'.str_pad($number, 7, '0', STR_PAD_LEFT);

            $lockedConfig->increment('current_number');

            return $formattedInvoice;
        });
    }
}
