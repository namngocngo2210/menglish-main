<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReleaseInstaller;
use RuntimeException;
use ZipArchive;

require_once __DIR__.'/../../deploy/release-install.php';

class ReleaseInstallerTest extends TestCase
{
    private string $root;

    private string $app;

    private string $public;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/release-installer-'.bin2hex(random_bytes(4));
        $this->app = $this->root.'/menglish';
        $this->public = $this->root.'/public_html';

        // Bản đang chạy trên hosting.
        $this->put($this->app.'/.env', 'DEPLOY_HOOK_TOKEN="'.str_repeat('t', 40)."\"\n");
        $this->put($this->app.'/app/Old.php', 'old');
        $this->put($this->app.'/app/Removed.php', 'removed');
        $this->put($this->app.'/storage/logs/laravel.log', 'log');
        $this->put($this->app.'/.ftp-deploy-sync-state.json', '{}');
        $this->put($this->public.'/index.php', 'old index');
        $this->put($this->public.'/uploads/2026/09/a.png', 'user file');
        $this->put($this->public.'/uploads/.htaccess', 'old rules');
        $this->put($this->public.'/_deploy-release.php', 'installer');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    public function test_install_swaps_code_and_keeps_host_data(): void
    {
        $zip = $this->makeZip([
            'app/app/Old.php' => 'new',
            'app/.env' => 'MUST_NOT_OVERWRITE=1',
            'app/storage/logs/.keep' => '',
            'app/storage/framework/views/.keep' => '',
            'public/index.php' => 'new index',
            'public/uploads/.htaccess' => 'new rules',
            'public/uploads/2026/09/a.png' => 'from repo',
            'public/uploads/tests/b.png' => 'asset',
        ]);

        $installer = new ReleaseInstaller($this->app, $this->public, '_deploy-release.php');
        $installer->install($zip);

        // Code được thay trọn thư mục: file đã xoá khỏi repo cũng biến mất.
        $this->assertSame('new', file_get_contents($this->app.'/app/Old.php'));
        $this->assertFileDoesNotExist($this->app.'/app/Removed.php');
        $this->assertSame('new index', file_get_contents($this->public.'/index.php'));

        // Dữ liệu trên hosting giữ nguyên, thư mục mới được tạo thêm.
        $this->assertStringContainsString('DEPLOY_HOOK_TOKEN', file_get_contents($this->app.'/.env'));
        $this->assertSame('log', file_get_contents($this->app.'/storage/logs/laravel.log'));
        $this->assertDirectoryExists($this->app.'/storage/framework/views');
        $this->assertSame('user file', file_get_contents($this->public.'/uploads/2026/09/a.png'));
        $this->assertSame('asset', file_get_contents($this->public.'/uploads/tests/b.png'));
        $this->assertSame('new rules', file_get_contents($this->public.'/uploads/.htaccess'));
        $this->assertFileExists($this->public.'/_deploy-release.php');

        // Bản cũ giữ để rollback; zip, thư mục tạm, state FTP cũ được dọn.
        $this->assertSame('removed', file_get_contents($this->app.'/storage/deploy/previous/app/app/Removed.php'));
        $this->assertSame('old index', file_get_contents($this->app.'/storage/deploy/previous/public/index.php'));
        $this->assertFileDoesNotExist($zip);
        $this->assertDirectoryDoesNotExist($this->app.'/storage/deploy/stage');
        $this->assertFileDoesNotExist($this->app.'/.ftp-deploy-sync-state.json');
    }

    public function test_second_install_keeps_only_latest_previous(): void
    {
        $installer = new ReleaseInstaller($this->app, $this->public);
        $installer->install($this->makeZip(['app/app/Old.php' => 'v2', 'public/index.php' => 'v2']));
        $installer->install($this->makeZip(['app/app/Old.php' => 'v3', 'public/index.php' => 'v3']));

        $this->assertSame('v3', file_get_contents($this->app.'/app/Old.php'));
        $this->assertSame('v2', file_get_contents($this->app.'/storage/deploy/previous/app/app/Old.php'));
    }

    public function test_rejects_broken_or_incomplete_release_without_touching_code(): void
    {
        $installer = new ReleaseInstaller($this->app, $this->public);

        $broken = $this->app.'/storage/deploy/release.zip';
        $this->put($broken, 'not a zip');
        try {
            $installer->install($broken);
            $this->fail('Expected exception');
        } catch (RuntimeException) {
        }

        $this->expectException(RuntimeException::class);
        try {
            $installer->install($this->makeZip(['app/app/Old.php' => 'new']));
        } finally {
            $this->assertSame('old', file_get_contents($this->app.'/app/Old.php'));
        }
    }

    public function test_env_value_reader(): void
    {
        $this->assertSame(str_repeat('t', 40), release_env_value($this->app.'/.env', 'DEPLOY_HOOK_TOKEN'));
        $this->assertSame('', release_env_value($this->app.'/.env', 'MISSING'));
        $this->assertSame('', release_env_value($this->root.'/nope', 'DEPLOY_HOOK_TOKEN'));
    }

    /** @param array<string, string> $files */
    private function makeZip(array $files): string
    {
        $path = $this->app.'/storage/deploy/release.zip';
        @mkdir(dirname($path), 0775, true);
        @unlink($path);
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    private function put(string $path, string $content): void
    {
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $content);
    }
}
