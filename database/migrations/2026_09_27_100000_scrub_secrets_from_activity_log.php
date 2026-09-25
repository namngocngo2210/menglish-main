<?php

use App\Support\SensitiveData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Che lại các khóa bí mật (SePay secret/api key, mật khẩu email, token...)
 * đã lỡ ghi vào nhật ký thao tác trước khi AuditOperationMiddleware được sửa.
 * Sau khi chạy, vẫn phải đổi khóa SePay và mật khẩu email vì chúng đã bị lộ.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('activitylog.table_name', 'activity_log');

        DB::table($table)
            ->whereNotNull('properties')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    $properties = json_decode((string) $row->properties, true);
                    if (! is_array($properties)) {
                        continue;
                    }

                    $masked = SensitiveData::mask($properties, PHP_INT_MAX);
                    if (isset($masked['url']) && is_string($masked['url'])) {
                        $masked['url'] = SensitiveData::maskUrl($masked['url']);
                    }

                    if ($masked !== $properties) {
                        DB::table($table)->where('id', $row->id)->update([
                            'properties' => json_encode($masked, JSON_UNESCAPED_UNICODE),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Không thể khôi phục giá trị đã che.
    }
};
