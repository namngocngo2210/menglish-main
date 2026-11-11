<?php

namespace App\Http\Middleware;

use App\Support\RoleSwitcher;
use Closure;
use Illuminate\Session\Middleware\StartSession;

/**
 * Thay StartSession trong nhóm "web". Trình duyệt đã nhập mã đổi vai trò (chỉ có trên bản thử nghiệm) thì phiên đăng
 * nhập sống RoleSwitcher::SESSION_MINUTES (trượt theo mỗi request) và cookie mở khoá được gia hạn; mọi người khác giữ
 * nguyên SESSION_LIFETIME. RoleSwitcher::enabled() sai (production) → unlocked() sai → không làm gì.
 */
class StartSessionWithRoleSwitcher extends StartSession
{
    public function handle($request, Closure $next)
    {
        $switcher = app(RoleSwitcher::class);
        $unlocked = $switcher->unlocked($request);
        if ($unlocked) {
            config(['session.lifetime' => RoleSwitcher::SESSION_MINUTES]);
        }

        $response = parent::handle($request, $next);

        if ($unlocked) {
            $response->headers->setCookie($switcher->cookie());
        }

        return $response;
    }
}
