<?php

namespace Tests\Feature;

use App\Models\CrmCustomer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Smoke test bền vững: tự liệt kê toàn bộ route GET không bắt buộc tham số
 * (tính tại runtime nên route mới thêm vào sẽ tự được phủ) rồi GET từng trang
 * với quyền admin để phát hiện trang chưa hoàn thiện (404/500) hoặc chặn nhầm
 * quyền admin (403). Redirect 3xx được chấp nhận (trang yêu cầu chọn học viên,
 * đăng nhập lại khi đã đăng nhập...).
 */
class AllRoutesSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_get_page_renders_for_admin(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        // Endpoint SePay trả 503 theo thiết kế khi webhook tắt — bật cấu hình để kiểm tra luồng 200.
        config(['services.sepay.webhook_enabled' => true]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        // Chốt hạ: seed phải tạo dữ liệu thật để smoke test không âm thầm chạy với DB rỗng.
        $this->assertGreaterThan(0, CrmCustomer::count(), 'Seeder phải tạo dữ liệu Lead.');

        $broken = [];
        $redirects = [];
        $ok = 0;

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            // Bỏ qua route còn tham số bắt buộc ({id}) — cần dữ liệu cụ thể, đã được phủ bởi test nghiệp vụ.
            $uri = preg_replace('/\{[^}]+\?\}/', '', $route->uri());
            if (str_contains($uri, '{')) {
                continue;
            }

            $url = '/'.preg_replace('#^/#', '', $uri);
            $response = $this->actingAs($admin)->get($url);
            $status = $response->baseResponse->getStatusCode();

            if ($status >= 200 && $status < 300) {
                $ok++;
            } elseif ($status >= 300 && $status < 400) {
                $redirects[] = sprintf('%s -> %s', $url, $response->headers->get('Location'));
            } else {
                $message = $response->exception?->getMessage();
                $broken[] = sprintf('%s [%d]%s', $url, $status, $message ? ' '.$message : '');
            }
        }

        fwrite(STDERR, sprintf(
            "\n=== All-routes smoke: %d OK, %d redirect, %d broken ===\n%s\n",
            $ok,
            count($redirects),
            count($broken),
            $broken ? "BROKEN:\n".implode("\n", $broken) : ''
        ));

        $this->assertSame([], $broken, count($broken).' route GET bị lỗi (xem chi tiết ở output).');
    }
}
