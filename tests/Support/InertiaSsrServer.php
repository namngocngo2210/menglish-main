<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Máy chủ render Vue phía server (Node, bootstrap/ssr/ssr.js) cho bộ test: trang Inertia trả HTML thật
 * nên assertSee / assertDontSee kiểm tra đúng nội dung người dùng thấy như với trang Blade.
 *
 * - Khởi động khi test đầu tiên cần (1 lần cho cả lượt chạy), tự tắt khi PHPUnit kết thúc.
 * - Bundle cũ hơn mã nguồn resources/js → tự build lại (`npx vite build --ssr`).
 */
final class InertiaSsrServer
{
    private static mixed $process = null;

    private static ?int $port = null;

    public static function url(): string
    {
        self::ensureRunning();

        return 'http://127.0.0.1:'.self::$port;
    }

    private static function ensureRunning(): void
    {
        if (self::$process !== null && proc_get_status(self::$process)['running']) {
            return;
        }

        $root = dirname(__DIR__, 2);
        self::ensureBundle($root);

        self::$port = self::freePort();
        $log = sys_get_temp_dir().'/menglish-inertia-ssr-'.self::$port.'.log';
        self::$process = proc_open(
            ['node', $root.'/bootstrap/ssr/ssr.js'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $root,
            [...getenv(), 'INERTIA_SSR_PORT' => (string) self::$port, 'NODE_ENV' => 'production'],
        );
        if (! is_resource(self::$process)) {
            throw new RuntimeException('Không chạy được Node để render trang Vue (cần Node trong PATH).');
        }
        register_shutdown_function(static function (): void {
            if (is_resource(self::$process)) {
                proc_terminate(self::$process);
            }
        });

        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            if (@file_get_contents('http://127.0.0.1:'.self::$port.'/health') !== false) {
                return;
            }
            usleep(50_000);
        }

        throw new RuntimeException('Máy chủ SSR không khởi động được, xem log: '.$log);
    }

    private static function ensureBundle(string $root): void
    {
        $bundle = $root.'/bootstrap/ssr/ssr.js';
        $sourceTime = max(self::newestMtime($root.'/resources/js'), filemtime($root.'/vite.config.js'));
        if (is_file($bundle) && filemtime($bundle) >= $sourceTime) {
            return;
        }

        exec('cd '.escapeshellarg($root).' && npx vite build --ssr 2>&1', $output, $code);
        if ($code !== 0) {
            throw new RuntimeException("Build SSR thất bại:\n".implode("\n", $output));
        }
    }

    private static function newestMtime(string $dir): int
    {
        $newest = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $newest = max($newest, $file->getMTime());
        }

        return $newest;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr(strrchr($name, ':'), 1);
    }
}
