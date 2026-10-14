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

    /**
     * route() chỉ có sẵn trong <template>; gọi trong <script> (vd. router.get(route(...)) khi đổi ngày / tháng báo cáo)
     * mà không import thì lỗi "route is not defined" và trang đứng yên. Bỏ qua dòng chú thích (ví dụ trong docblock).
     */
    public function test_script_blocks_import_route_before_calling_it(): void
    {
        $missing = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'vue') {
                continue;
            }
            preg_match_all('#<script[^>]*>(.*?)</script>#s', file_get_contents($file->getPathname()), $scripts);
            foreach ($scripts[1] as $script) {
                $code = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $script);
                if (preg_match('/(?<![\w.$])route\(/', $code) && ! preg_match('/import\s*\{[^}]*\broute\b[^}]*\}\s*from/', $code)) {
                    $missing[] = str_replace(resource_path('js').'/', '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $missing, "Thêm import { route } from '@/lib/route'; vào <script> của các file này.");
    }

    /** Chỉ so danh sách route (bỏ url / port của máy sinh file). */
    private function routesOf(string $js): array
    {
        preg_match('/const Ziggy = (\{.*?\});\n/s', $js, $m);

        return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR)['routes'];
    }
}
