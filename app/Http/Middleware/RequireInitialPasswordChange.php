<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireInitialPasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        // role-switcher.switch: tài khoản bắt đổi mật khẩu vẫn chuyển được sang vai trò khác (chỉ bản thử nghiệm).
        $allowedRoutes = ['profile.edit', 'password.update', 'logout', 'role-switcher.switch'];

        if ($user?->must_change_password && ! in_array($request->route()?->getName(), $allowedRoutes, true)) {
            return redirect()->route('profile.edit', ['force_password' => 1]);
        }

        return $next($request);
    }
}
