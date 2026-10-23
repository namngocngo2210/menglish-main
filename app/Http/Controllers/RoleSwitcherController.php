<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Models\User;
use App\Support\RoleSwitcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Đổi vai trò trên bản thử nghiệm (App\Support\RoleSwitcher): nhập mã truy cập chung, rồi đăng nhập thẳng bằng tài
 * khoản đã chọn (chưa đăng nhập hay đang là ai cũng được). Tắt (production, chưa cấu hình) → 404.
 */
class RoleSwitcherController extends Controller
{
    /** Số lần nhập sai mã tối đa mỗi phút từ một IP. */
    private const MAX_ATTEMPTS = 5;

    public function unlock(Request $request, RoleSwitcher $switcher): RedirectResponse
    {
        abort_unless(RoleSwitcher::enabled(), 404);

        $key = 'role-switcher:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'code' => 'Nhập sai quá nhiều lần, thử lại sau '.RateLimiter::availableIn($key).' giây.',
            ]);
        }

        $request->validate(['code' => ['required', 'string', 'max:255']]);
        if (! $switcher->checkCode($request->string('code')->toString())) {
            RateLimiter::hit($key);
            activity('auth')->log('Đổi vai trò thử nghiệm: nhập sai mã truy cập');

            throw ValidationException::withMessages(['code' => 'Mã truy cập không đúng.']);
        }

        RateLimiter::clear($key);

        return back()->withCookie($switcher->cookie());
    }

    public function switch(Request $request, User $user, RoleSwitcher $switcher): Response
    {
        abort_unless(RoleSwitcher::enabled(), 404);
        abort_unless($switcher->unlocked($request), 403, 'Nhập mã truy cập ở trang đăng nhập trước khi đổi vai trò.');
        abort_unless($switcher->canSwitchTo($user), 403, 'Tài khoản này đã bị khóa hoặc chưa có vai trò.');

        $from = $request->user();
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->forget('url.intended');

        activity('auth')
            ->causedBy($user)
            ->withProperties(['from_user_id' => $from?->id])
            ->log('Đổi vai trò thử nghiệm: '.($from?->name ?? 'khách')." → {$user->name}");

        // Như đăng nhập: tải lại hẳn trang đầu của tài khoản mới (phiên, token CSRF, menu đổi hết).
        return Inertia::location(redirect(AuthenticatedSessionController::homeUrl($user)));
    }
}
