<?php

namespace Tests;

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Inertia\Ssr\Gateway;
use Inertia\Ssr\SsrState;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SsrTestGateway;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seeder nạp MỘT LẦN vào database test (cùng lúc migrate) thay vì chạy lại ở mọi test.
     * Vai trò + quyền mất ~1,3 giây mỗi lần nạp — trước đây chiếm phần lớn thời gian của bộ test.
     */
    protected $seeder = TestBaseSeeder::class;

    /** RefreshDatabase: nạp seeder trên cùng lúc migrate (chỉ 1 lần cho cả lượt chạy). */
    protected $seed = true;

    protected function setUp(): void
    {
        // Database test dạng file (phpunit.xml): tạo sẵn khi chưa có (CI / máy mới clone).
        $testDb = dirname(__DIR__).'/database/testing.sqlite';
        if (! file_exists($testDb)) {
            touch($testDb);
        }

        parent::setUp();
        \Illuminate\Support\Facades\Mail::fake();
        $this->renderInertiaPagesAsHtml();
    }

    /**
     * Trang Inertia (Vue) trong test trả HTML thật (render bằng Node — tests/Support/InertiaSsrServer) để
     * assertSee / assertDontSee kiểm tra nội dung hiển thị như trang Blade. Không cần asset Vite trong test.
     */
    private function renderInertiaPagesAsHtml(): void
    {
        $this->withoutVite();
        config(['inertia.ssr.enabled' => true]);
        $this->app->instance(Gateway::class, new SsrTestGateway);
        // SsrState giữ kết quả render theo request: nhiều request trong 1 test → mỗi request render lại.
        $this->app['events']->listen(RequestHandled::class, fn () => $this->app->forgetInstance(SsrState::class));
    }

    /**
     * Vai trò / quyền đã có sẵn trong database test (mỗi test chạy trong transaction nên dữ liệu gốc không đổi):
     * bỏ qua lệnh nạp lại PermissionSeeder / RoleSeeder trong từng test, các seeder khác chạy bình thường.
     */
    public function seed($class = 'Database\\Seeders\\DatabaseSeeder')
    {
        $classes = is_array($class) ? $class : [$class];
        $remaining = array_values(array_filter($classes, fn ($c) => ! in_array($c, [PermissionSeeder::class, RoleSeeder::class], true)));

        if ($remaining === []) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $this;
        }

        return parent::seed(count($remaining) === 1 ? $remaining[0] : $remaining);
    }

    /** Chạy seeder thật (kể cả PermissionSeeder / RoleSeeder) — dùng cho test kiểm tra chính việc nạp lại seeder. */
    protected function runSeederForReal(string $class): static
    {
        parent::seed($class);

        return $this;
    }
}
