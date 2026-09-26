<?php

/*
 * public_html/_deploy-release.php — cài bản release (release.zip) trên shared hosting DirectAdmin.
 *
 * Chạy ĐỘC LẬP với Laravel (không autoload, không boot app) để vẫn deploy được bản sửa
 * kể cả khi bản đang chạy bị hỏng. Workflow deploy thay __APP_DIR__ bằng tên thư mục thật.
 *
 * Luồng: CI upload release.zip vào <APP_DIR>/storage/deploy/ → POST tới file này kèm header
 * X-Deploy-Token (so với DEPLOY_HOOK_TOKEN trong <APP_DIR>/.env) → giải nén vào thư mục tạm →
 * đổi tên (rename) từng mục cấp cao nhất vào chỗ → bản cũ giữ ở storage/deploy/previous để rollback tay.
 * Sau đó CI gọi /_deploy/hook (Laravel) để migrate + làm mới cache.
 */

final class ReleaseInstaller
{
    /** Mục KHÔNG bao giờ bị thay: cấu hình & dữ liệu trên hosting. Nếu zip có, chỉ chép thêm file (merge). */
    private const APP_PRESERVE = ['.env', 'storage'];

    private const PUBLIC_PRESERVE = ['uploads', 'storage'];

    /** @var list<string> */
    private array $log = [];

    public function __construct(
        private readonly string $appPath,
        private readonly string $publicPath,
        private readonly string $selfName = '',
    ) {}

    /** @return list<string> */
    public function log(): array
    {
        return $this->log;
    }

    public function install(string $zipPath): void
    {
        if (! is_file($zipPath)) {
            throw new RuntimeException("Không thấy file release: {$zipPath}");
        }

        $workDir = $this->appPath.'/storage/deploy';
        $stage = $workDir.'/stage';
        $previous = $workDir.'/previous';
        $this->removeTree($stage);
        $this->ensureDir($stage);

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Không mở được release.zip (file hỏng hoặc upload dở).');
        }
        if (! $zip->extractTo($stage)) {
            $zip->close();
            throw new RuntimeException('Giải nén release.zip thất bại (hết dung lượng?).');
        }
        $zip->close();
        $this->log[] = 'Đã giải nén release.zip';

        foreach (['app', 'public'] as $part) {
            if (! is_dir("{$stage}/{$part}")) {
                throw new RuntimeException("release.zip thiếu thư mục {$part}/");
            }
        }

        // Chỉ giữ 1 bản trước đó để rollback.
        $this->removeTree($previous);
        $this->ensureDir($previous.'/app');
        $this->ensureDir($previous.'/public');

        $this->swap("{$stage}/app", $this->appPath, "{$previous}/app", self::APP_PRESERVE);
        $this->swap("{$stage}/public", $this->publicPath, "{$previous}/public", [...self::PUBLIC_PRESERVE, $this->selfName]);

        $this->removeTree($stage);
        @unlink($zipPath);
        // File state của cách deploy FTP-sync cũ, không còn dùng.
        @unlink($this->appPath.'/.ftp-deploy-sync-state.json');
        @unlink($this->publicPath.'/.ftp-deploy-sync-state.json');

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        $this->log[] = 'Đã cài release, bản cũ ở storage/deploy/previous';
    }

    /**
     * Thay từng mục cấp cao nhất của $target bằng mục tương ứng trong $source (rename, gần như tức thời);
     * mục cũ chuyển sang $backup. Mục trong $preserve chỉ được merge (chép thêm/ghi đè file, không xoá).
     *
     * @param  list<string>  $preserve
     */
    private function swap(string $source, string $target, string $backup, array $preserve): void
    {
        $this->ensureDir($target);
        $count = 0;
        foreach ($this->entries($source) as $name) {
            $from = "{$source}/{$name}";
            $to = "{$target}/{$name}";

            if (in_array($name, $preserve, true)) {
                $this->merge($from, $to);

                continue;
            }
            if (file_exists($to) || is_link($to)) {
                $this->rename($to, "{$backup}/{$name}");
            }
            $this->rename($from, $to);
            $count++;
        }
        $this->log[] = "Đã thay {$count} mục trong ".basename($target);
    }

    private function merge(string $from, string $to): void
    {
        if (is_file($from)) {
            if (! is_file($to)) {
                $this->rename($from, $to);
            }

            return;
        }
        $this->ensureDir($to);
        foreach ($this->entries($from) as $name) {
            $src = "{$from}/{$name}";
            $dst = "{$to}/{$name}";
            if (is_dir($src)) {
                $this->merge($src, $dst);
            } else {
                // File dữ liệu trên hosting (ảnh, log…) là nguồn đúng; chỉ ghi đè file cấu hình đi kèm code.
                if (! file_exists($dst) || basename($src) === '.htaccess') {
                    $this->rename($src, $dst);
                }
            }
        }
    }

    /** @return list<string> */
    private function entries(string $dir): array
    {
        return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    }

    private function rename(string $from, string $to): void
    {
        if (is_file($to) && ! is_dir($from)) {
            @unlink($to);
        }
        if (! @rename($from, $to)) {
            throw new RuntimeException("Không đổi tên được {$from} → {$to}");
        }
    }

    private function ensureDir(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Không tạo được thư mục {$dir}");
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}

/** Đọc một biến từ file .env mà không cần Laravel. */
function release_env_value(string $envFile, string $key): string
{
    if (! is_readable($envFile)) {
        return '';
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*(.*)$/', $line, $m)) {
            return trim(trim($m[1]), '"\'');
        }
    }

    return '';
}

// Chỉ chạy như endpoint web; CLI (test) chỉ nạp class/hàm.
if (PHP_SAPI === 'cli') {
    return;
}

$appPath = realpath(__DIR__.'/../__APP_DIR__') ?: __DIR__.'/../__APP_DIR__';
$expected = release_env_value($appPath.'/.env', 'DEPLOY_HOOK_TOKEN');
$given = (string) ($_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '');

header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || strlen($expected) < 32 || ! hash_equals($expected, $given)) {
    http_response_code(404);
    echo '{"ok":false}';

    return;
}

@set_time_limit(300);
ignore_user_abort(true);

$installer = new ReleaseInstaller($appPath, __DIR__, basename(__FILE__));
try {
    $installer->install($appPath.'/storage/deploy/release.zip');
    echo json_encode(['ok' => true, 'steps' => $installer->log()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'steps' => $installer->log(), 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
