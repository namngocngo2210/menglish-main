<?php

namespace App\Http\Middleware;

use App\Support\RoleSwitcher;
use Closure;
use Illuminate\Contracts\Session\Session;
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

    /**
     * Mọi request (kể cả trình duyệt chưa mở khoá, SESSION_LIFETIME=120) có thể kích hoạt GC của driver database và xoá
     * phiên cũ hơn 120 phút — gồm cả phiên đổi vai trò. Khi bật thì GC chỉ xoá phiên quá hạn dài nhất (30 ngày).
     */
    protected function collectGarbage(Session $session)
    {
        if (! RoleSwitcher::enabled()) {
            parent::collectGarbage($session);

            return;
        }

        if ($this->configHitsLottery($this->manager->getSessionConfig())) {
            $session->getHandler()->gc(RoleSwitcher::SESSION_MINUTES * 60);
        }
    }
}
