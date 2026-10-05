<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header bảo mật cho mọi phản hồi của ứng dụng (file tĩnh do web server trả không đi qua đây).
 * - X-Frame-Options / X-Content-Type-Options / Referrer-Policy: chống nhúng trang vào site khác, đoán kiểu file, lộ URL (có mã ký) sang bên thứ ba.
 * - Permissions-Policy: chỉ cho trang của mình dùng camera (chấm công mặt), vị trí (chấm công GPS), micro (luyện phát âm).
 * - HSTS chỉ khi đang chạy https, để môi trường http cục bộ không bị trình duyệt ép sang https.
 * Chưa đặt Content-Security-Policy đầy đủ: trang có ảnh VietQR bên ngoài, iframe mockup và dữ liệu nhúng, cần đo kỹ trước khi bật.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(self), geolocation=(self), microphone=(self)',
        ];
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
        foreach ($headers as $name => $value) {
            // Không ghi đè header do controller đã đặt (vd. tải file đặt nosniff riêng).
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
