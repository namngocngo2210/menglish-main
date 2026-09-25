<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SepayConfiguration extends Model
{
    use HasFactory;

    protected $table = 'sepay_configurations';

    protected $fillable = [
        'webhook_name',
        'webhook_url',
        'transaction_type',
        'data_format',
        'auth_method',
        'api_key',
        'secret_key',
        'is_active',
        'auto_retry',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'auto_retry' => 'boolean',
    ];

    public static function getActiveConfig(): self
    {
        $secret = env('SEPAY_WEBHOOK_SECRET');

        // Không bao giờ insert secret_key = NULL (cột NOT NULL trên một số môi trường gây 500):
        // thiếu biến môi trường thì sinh secret tạm và giữ cấu hình ở trạng thái tắt.
        return static::firstOrCreate([], [
            'webhook_name' => 'Xác Thực Thanh Toán Meducation',
            'webhook_url' => 'https://dungthu.meducation.vn/hook/sepay-gateway/v1/add-payment',
            'transaction_type' => 'in',
            'data_format' => 'json',
            'auth_method' => 'hmac_sha256',
            'secret_key' => $secret ?: Str::random(64),
            'is_active' => ! empty($secret),
            'auto_retry' => true,
        ]);
    }
}
