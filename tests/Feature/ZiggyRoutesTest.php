<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * route() trong Vue đọc danh sách route sinh sẵn ở resources/js/ziggy.js. Đổi routes/web.php mà quên sinh lại
 * → trang Vue gọi route mới bị lỗi. Sửa: php artisan ziggy:generate resources/js/ziggy.js
 */
class ZiggyRoutesTest extends TestCase
{
    public function test_generated_route_list_is_up_to_date(): void
    {
        $relative = 'storage/framework/ziggy-'.uniqid().'.js';
        Artisan::call('ziggy:generate', ['path' => $relative]);
        $fresh = base_path($relative);

        $this->assertSame(
            $this->routesOf(file_get_contents($fresh)),
            $this->routesOf(file_get_contents(resource_path('js/ziggy.js'))),
            'resources/js/ziggy.js đã cũ — chạy: php artisan ziggy:generate resources/js/ziggy.js',
        );
        @unlink($fresh);
    }

    /** Chỉ so danh sách route (bỏ url / port của máy sinh file). */
    private function routesOf(string $js): array
    {
        preg_match('/const Ziggy = (\{.*?\});\n/s', $js, $m);

        return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR)['routes'];
    }
}
