<?php

namespace Tests\Support;

use Inertia\Ssr\Gateway;
use Inertia\Ssr\Response;
use RuntimeException;

/**
 * Gateway SSR cho test: gọi thẳng máy chủ Node bằng curl (không qua Http facade — test dùng Http::fake không chặn nhầm)
 * và trả HTML "sạch" để so sánh như trang Blade:
 *   - bỏ comment đánh dấu hydration của Vue (<!--[-->, <!--]-->, <!---->, <!--v-if-->)
 *   - bỏ khối JSON dữ liệu trang (<script data-page>) — assert chỉ nhìn phần hiển thị
 * Lỗi render → ném exception (test hỏng rõ ràng thay vì âm thầm trả trang rỗng).
 */
final class SsrTestGateway implements Gateway
{
    public function dispatch(array $page): ?Response
    {
        $curl = curl_init(InertiaSsrServer::url().'/render');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($page, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if ($body === false || $status !== 200) {
            throw new RuntimeException("Render trang Vue [{$page['component']}] thất bại ({$status}): ".($body ?: curl_error($curl)));
        }

        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        return new Response(implode("\n", $data['head'] ?? []), self::clean($data['body'] ?? ''));
    }

    public static function clean(string $html): string
    {
        $html = preg_replace('/<script data-page="[^"]*" type="application\/json">.*?<\/script>/s', '', $html);

        return preg_replace('/<!--(?:\[|\]|v-if|teleport (?:start|end)|)-->/', '', $html);
    }
}
